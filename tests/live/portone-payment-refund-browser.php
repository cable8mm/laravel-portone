<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath !== '/refund') {
    return false;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$sessionDirectory = getenv('PORTONE_REFUND_SESSION_DIR')
    ?: $_SERVER['PORTONE_REFUND_SESSION_DIR']
    ?? $_ENV['PORTONE_REFUND_SESSION_DIR']
    ?? '';
$checkoutPath = $sessionDirectory.'/checkout.json';
$resultPath = $sessionDirectory.'/results.json';

if ($sessionDirectory === '' || ! is_file($checkoutPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $sessionDirectory === ''
        ? 'The refund session directory is not configured.'
        : 'Waiting for package-generated checkout parameters.';

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode(file_get_contents('php://input'), true);
    $checkout = json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR);
    $index = is_array($request) ? ($request['index'] ?? null) : null;
    $paymentId = is_array($request) ? ($request['paymentId'] ?? null) : null;

    if (! is_string($index)
        || ! isset($checkout[$index])
        || ! is_array($checkout[$index])
        || ($checkout[$index]['paymentId'] ?? null) !== $paymentId
    ) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid refund payment result.';

        return;
    }

    $results = is_file($resultPath)
        ? json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR)
        : [];
    $results[$index] = [
        'paymentId' => $paymentId,
        'hasError' => ($request['hasError'] ?? true) === true,
    ];
    file_put_contents($resultPath, json_encode($results, JSON_THROW_ON_ERROR), LOCK_EX);

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Result received.';

    return;
}

$configuration = [
    'storeId' => getenv('PORTONE_STORE_ID') ?: $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '',
    'channelKey' => getenv('PORTONE_CHANNEL_KEY') ?: $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '',
];
$checkouts = json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR);

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PortOne package refund test</title>
</head>
<body>
    <main>
        <h1>PortOne package refund test</h1>
        <p>Complete both package-generated 10000 KRW test payments before continuing.</p>
        <ol id="payments"></ol>
        <p id="status" role="status">Ready</p>
    </main>
    <script id="portone-config" type="application/json"><?= json_encode(
        ['config' => $configuration, 'checkouts' => $checkouts],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script type="module">
        import PortOne from "/node_modules/@portone/browser-sdk/dist/v2.js";

        const { config, checkouts } = JSON.parse(document.querySelector("#portone-config").textContent);
        const list = document.querySelector("#payments");
        const status = document.querySelector("#status");

        for (const [index, checkout] of Object.entries(checkouts)) {
            const item = document.createElement("li");
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = `Pay ${index} test payment`;
            button.addEventListener("click", async () => {
                button.disabled = true;
                status.textContent = `Complete the ${index} payment in the PortOne test window.`;
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

                await fetch("/refund", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ index, paymentId: checkout.paymentId, hasError }),
                });
                status.textContent = hasError
                    ? `Payment ${index} failed.`
                    : `Payment ${index} completed; proceed to the next one.`;
            });
            item.append(button);
            list.append(item);
        }
    </script>
</body>
</html>
