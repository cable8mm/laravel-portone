<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// As the PHP built-in server's router, leave every other path (the SDK under
// /node_modules) to be served as a static file from the package root.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/') {
    return false;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$storeId = $_ENV['PORTONE_STORE_ID'] ?? '';
$channelKey = $_ENV['PORTONE_CHANNEL_KEY'] ?? '';

if ($storeId === '' || $channelKey === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PORTONE_STORE_ID and PORTONE_CHANNEL_KEY must be set in the local .env file.';

    return;
}

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PortOne browser SDK live probe</title>
</head>
<body>
    <main>
        <h1>PortOne browser SDK live probe</h1>
        <p>
            This standalone probe uses the configured PortOne channel and a KRW 100
            test payment. Complete the payment in the PG window for one run; close
            the window without paying for another. The third button provokes a
            failure of the SDK call itself with a payment ID the PG rejects.
        </p>
        <button id="complete-payment" type="button">Start payment to complete</button>
        <button id="close-payment" type="button">Start payment to close without paying</button>
        <button id="overlong-payment" type="button">Start payment with an over-long payment ID</button>
        <ol id="observations" aria-live="polite"></ol>
    </main>

    <script
        id="portone-config"
        type="application/json"
    ><?= json_encode(
        ['storeId' => $storeId, 'channelKey' => $channelKey],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script type="module">
        import PortOne from "/node_modules/@portone/browser-sdk/dist/v2.js";

        const config = JSON.parse(document.querySelector("#portone-config").textContent);
        const observations = document.querySelector("#observations");
        const buttons = [
            document.querySelector("#complete-payment"),
            document.querySelector("#close-payment"),
            document.querySelector("#overlong-payment"),
        ];

        function recordObservation(fields) {
            const item = document.createElement("li");
            item.textContent = JSON.stringify({
                observedAt: new Date().toISOString(),
                ...fields,
            });
            observations.prepend(item);
        }

        async function requestPayment(event) {
            // KCP V2 rejects a payment ID longer than 40 characters; the UUID's dashes push it to 42.
            const paymentId = event.currentTarget.id === "overlong-payment"
                ? `probe-${crypto.randomUUID()}`
                : `probe-${crypto.randomUUID().replaceAll("-", "")}`;
            const customerId = `probe-${crypto.randomUUID().replaceAll("-", "")}`;

            buttons.forEach((button) => {
                button.disabled = true;
            });
            recordObservation({
                paymentId,
                paymentIdLength: paymentId.length,
                amount: 100,
                currency: "KRW",
                payMethod: "CARD",
                requestPayment: "pending; finish or close the PG window",
            });

            try {
                const result = await PortOne.requestPayment({
                    storeId: config.storeId,
                    channelKey: config.channelKey,
                    paymentId,
                    orderName: "PortOne browser SDK live probe",
                    totalAmount: 100,
                    customer: { customerId },
                    payMethod: "CARD",
                    currency: "KRW",
                });

                recordObservation({
                    paymentId,
                    amount: 100,
                    outcome: result === undefined
                        ? "SDK returned no result"
                        : result.code
                            ? "error code present"
                            : "no error code",
                    ...(typeof result?.code === "string" ? { code: result.code } : {}),
                    ...(typeof result?.paymentId === "string" ? { returnedPaymentId: result.paymentId } : {}),
                });
            } catch (error) {
                const message = error instanceof Error ? error.message : "";
                const redactedMessage = [config.storeId, config.channelKey, paymentId]
                    .filter(Boolean)
                    .reduce((value, sensitiveValue) => value.replaceAll(sensitiveValue, "[redacted]"), message);

                recordObservation({
                    paymentId,
                    amount: 100,
                    outcome: "SDK call threw or rejected",
                    errorName: error instanceof Error ? error.name : "UnknownError",
                    ...(redactedMessage ? { errorMessage: redactedMessage.slice(0, 240) } : {}),
                    ...(typeof error?.code === "string" ? { code: error.code } : {}),
                });
            } finally {
                buttons.forEach((button) => {
                    button.disabled = false;
                });
            }
        }

        buttons.forEach((button) => {
            button.addEventListener("click", requestPayment);
        });
    </script>
</body>
</html>
