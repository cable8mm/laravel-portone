<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath !== '/webhook') {
    return false;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$sessionDirectory = getenv('PORTONE_WEBHOOK_SESSION_DIR')
    ?: $_SERVER['PORTONE_WEBHOOK_SESSION_DIR']
    ?? $_ENV['PORTONE_WEBHOOK_SESSION_DIR']
    ?? '';
$checkoutPath = $sessionDirectory.'/checkout.json';
$resultPath = $sessionDirectory.'/result.json';

if ($sessionDirectory === '' || ! is_file($checkoutPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $sessionDirectory === ''
        ? 'The webhook session directory is not configured.'
        : 'Waiting for package-generated checkout parameters.';

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode(file_get_contents('php://input'), true);
    $checkout = json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR);
    $paymentId = is_array($request) ? ($request['paymentId'] ?? null) : null;
    if (! is_string($paymentId) || $paymentId !== ($checkout['paymentId'] ?? null)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid webhook payment result.';

        return;
    }

    file_put_contents(
        $resultPath,
        json_encode([
            'paymentId' => $paymentId,
            'hasError' => ($request['hasError'] ?? true) === true,
        ], JSON_THROW_ON_ERROR),
        LOCK_EX,
    );
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Result received.';

    return;
}

$configuration = [
    'storeId' => getenv('PORTONE_STORE_ID') ?: $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '',
    'channelKey' => getenv('PORTONE_CHANNEL_KEY') ?: $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '',
];
$checkout = json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR);

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PortOne webhook verification</title>
</head>
<body>
    <main>
        <h1>PortOne webhook verification</h1>
        <p>Complete the package-generated 100 KRW test payment.</p>
        <button id="pay" type="button">Pay test payment</button>
        <p id="status" role="status">Ready</p>
    </main>
    <script id="portone-config" type="application/json"><?= json_encode(
        ['config' => $configuration, 'checkout' => $checkout],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script type="module">
        import PortOne from "/node_modules/@portone/browser-sdk/dist/v2.js";

        const { config, checkout } = JSON.parse(document.querySelector("#portone-config").textContent);
        const button = document.querySelector("#pay");
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
            } catch {
                hasError = true;
            }

            await fetch("/webhook", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ paymentId: checkout.paymentId, hasError }),
            });
            status.textContent = hasError ? "Payment failed." : "Payment completed.";
        });
    </script>
</body>
</html>
