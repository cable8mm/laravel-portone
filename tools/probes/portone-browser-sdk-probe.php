<?php

declare(strict_types=1);

use Cable8mm\PortonePhp\Client\PortOneClient;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Dotenv\Dotenv;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// As the PHP built-in server's router, leave every other path (the SDK under
// /node_modules) to be served as a static file from the package root.
if (! in_array($path, ['/', '/lookup', '/observe'], true)) {
    return false;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

// Every lookup and browser observation of the abandoned-payment scenarios (T-021)
// is appended here so it can be read after the run; it holds no secrets.
$logPath = __DIR__.'/portone-abandoned-payment-log.jsonl';

$appendLog = static function (array $record) use ($logPath): void {
    file_put_contents(
        $logPath,
        json_encode(['loggedAt' => gmdate(DATE_ATOM)] + $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
        FILE_APPEND | LOCK_EX,
    );
};

if ($path === '/observe') {
    $record = json_decode((string) file_get_contents('php://input'), true);
    $appendLog(['source' => 'browser', 'observation' => is_array($record) ? $record : null]);
    http_response_code(204);

    return;
}

if ($path === '/lookup') {
    // Looks the payment up with the PortOne client only, never through the Package.
    $paymentId = (string) ($_GET['paymentId'] ?? '');
    $apiSecret = $_ENV['PORTONE_API_SECRET'] ?? '';
    header('Content-Type: application/json; charset=UTF-8');

    if ($apiSecret === '' || $paymentId === '') {
        http_response_code(400);
        echo json_encode(['result' => 'error', 'reason' => 'PORTONE_API_SECRET and paymentId are required']);

        return;
    }

    try {
        $payment = (new PortOneClient($apiSecret))->payment->getPayment($paymentId);
        $amount = $payment->data['amount'] ?? null;
        $lookup = [
            'result' => 'success',
            'status' => $payment->variant,
            'totalAmount' => is_array($amount) && is_int($amount['total'] ?? null) ? $amount['total'] : null,
            'paidAmount' => is_array($amount) && is_int($amount['paid'] ?? null) ? $amount['paid'] : null,
        ];
    } catch (Throwable $exception) {
        $lookup = [
            'result' => 'error',
            'exception' => $exception::class,
            'httpStatus' => $exception instanceof PortOneException ? $exception->status : null,
            'errorType' => $exception instanceof PortOneException ? $exception->errorType : null,
        ];
    }

    $appendLog(['source' => 'lookup', 'paymentId' => $paymentId, 'scenario' => (string) ($_GET['scenario'] ?? ''), 'lookup' => $lookup]);
    echo json_encode($lookup, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return;
}

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

        <h2>Abandoned payments (T-021)</h2>
        <p>
            Every payment below is also looked up with the PortOne API directly (not the
            Package) and logged to tools/probes/portone-abandoned-payment-log.jsonl.
        </p>
        <ol>
            <li>
                <b>Window left open:</b> click the button, then leave the PG window open
                without paying for about 30 seconds while the page looks the payment up
                every 3 seconds; then close the window without paying.
            </li>
            <li>
                <b>Retry after closing:</b> click the button to reopen the PG window with
                the payment ID you just closed, and complete the payment this time.
            </li>
        </ol>
        <button id="open-window-payment" type="button">1. Start payment and keep the window open</button>
        <button id="retry-closed-payment" type="button" disabled>2. Retry the last closed payment ID</button>
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
        const retryButton = document.querySelector("#retry-closed-payment");
        const buttons = [
            document.querySelector("#complete-payment"),
            document.querySelector("#close-payment"),
            document.querySelector("#overlong-payment"),
            document.querySelector("#open-window-payment"),
            retryButton,
        ];
        let lastClosedPaymentId = null;

        function recordObservation(fields) {
            const observation = {
                observedAt: new Date().toISOString(),
                ...fields,
            };
            const item = document.createElement("li");
            item.textContent = JSON.stringify(observation);
            observations.prepend(item);
            fetch("/observe", { method: "POST", body: JSON.stringify(observation) }).catch(() => {});
        }

        async function lookUp(paymentId, scenario) {
            const response = await fetch(`/lookup?${new URLSearchParams({ paymentId, scenario })}`);
            const lookup = await response.json();
            recordObservation({ paymentId, scenario, lookup });

            return lookup;
        }

        async function requestPayment(event) {
            const scenario = event.currentTarget.id;
            // KCP V2 rejects a payment ID longer than 40 characters; the UUID's dashes push it to 42.
            const paymentId = scenario === "overlong-payment"
                ? `probe-${crypto.randomUUID()}`
                : scenario === "retry-closed-payment"
                    ? lastClosedPaymentId
                    : `probe-${crypto.randomUUID().replaceAll("-", "")}`;
            const customerId = `probe-${crypto.randomUUID().replaceAll("-", "")}`;
            let polling = null;

            buttons.forEach((button) => {
                button.disabled = true;
            });
            recordObservation({
                paymentId,
                paymentIdLength: paymentId.length,
                amount: 100,
                currency: "KRW",
                payMethod: "CARD",
                scenario,
                requestPayment: "pending; finish or close the PG window",
            });

            if (scenario === "open-window-payment") {
                // Look the payment up while the PG window is still open.
                const startedAt = Date.now();
                polling = setInterval(() => {
                    lookUp(paymentId, `${scenario}: window open ${Math.round((Date.now() - startedAt) / 1000)}s`).catch(() => {});
                }, 3000);
            }

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
                    scenario,
                });

                if (result?.code) {
                    lastClosedPaymentId = paymentId;
                }

                clearInterval(polling);
                await lookUp(paymentId, `${scenario}: after the SDK returned`);
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
                clearInterval(polling);
                buttons.forEach((button) => {
                    button.disabled = false;
                });
                retryButton.disabled = lastClosedPaymentId === null;
            }
        }

        buttons.forEach((button) => {
            button.addEventListener("click", requestPayment);
        });
    </script>
</body>
</html>
