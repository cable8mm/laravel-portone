<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath !== '/completion') {
    return false;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$sessionDirectory = getenv('PORTONE_COMPLETION_SESSION_DIR')
    ?: $_SERVER['PORTONE_COMPLETION_SESSION_DIR']
    ?? $_ENV['PORTONE_COMPLETION_SESSION_DIR']
    ?? '';
$checkoutPath = $sessionDirectory.'/checkout.json';
$resultPath = $sessionDirectory.'/result.json';

if ($sessionDirectory === '' || ! is_file($checkoutPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $sessionDirectory === ''
        ? 'The checkout session directory is not configured.'
        : 'Waiting for package-generated checkout parameters.';

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode(file_get_contents('php://input'), true);
    $checkout = json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($request) || ($request['paymentId'] ?? null) !== ($checkout['paymentId'] ?? null)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid completion result.';

        return;
    }

    file_put_contents(
        $resultPath,
        json_encode([
            'paymentId' => $request['paymentId'],
            'hasError' => ($request['hasError'] ?? true) === true,
        ], JSON_THROW_ON_ERROR),
        LOCK_EX,
    );

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Result received.';

    return;
}

$apiConfiguration = [
    'storeId' => getenv('PORTONE_STORE_ID') ?: $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '',
    'channelKey' => getenv('PORTONE_CHANNEL_KEY') ?: $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '',
];
$checkoutParameters = json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR);

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PortOne package completion test</title>
</head>
<body>
    <main>
        <h1>PortOne package completion test</h1>
        <p>Use the PortOne test channel to complete this package-generated 100 KRW payment.</p>
        <button id="start-payment" type="button">Start test payment</button>
        <p id="status" role="status">Ready</p>
    </main>
    <script id="portone-config" type="application/json"><?= json_encode(
        ['config' => $apiConfiguration, 'checkout' => $checkoutParameters],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script type="module">
        import PortOne from "/node_modules/@portone/browser-sdk/dist/v2.js";

        const { config, checkout } = JSON.parse(document.querySelector("#portone-config").textContent);
        const button = document.querySelector("#start-payment");
        const status = document.querySelector("#status");

        button.addEventListener("click", async () => {
            button.disabled = true;
            status.textContent = "Complete the payment in the PortOne test window.";

            let hasError = true;
            try {
                const result = await PortOne.requestPayment({
                    storeId: config.storeId,
                    channelKey: config.channelKey,
                    paymentId: checkout.paymentId,
                    orderName: checkout.orderName,
                    totalAmount: checkout.totalAmount,
                    customer: { customerId: checkout.customerId },
                    payMethod: "CARD",
                    currency: "KRW",
                });
                hasError = result === undefined || Boolean(result.code);
                status.textContent = hasError
                    ? "The payment did not complete. The test will report the observed failure."
                    : "Payment succeeded. The package is now checking its status.";
            } catch {
                status.textContent = "The payment SDK failed. The test will report the observed failure.";
            }

            await fetch("/completion", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ paymentId: checkout.paymentId, hasError }),
            });
        });
    </script>
</body>
</html>
