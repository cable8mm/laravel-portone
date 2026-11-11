<?php

declare(strict_types=1);

// Live observation of billing-key issuance, charge, failure, cancellation,
// deletion, and the BillingKey webhooks (T-045). Run as the PHP built-in
// server's router from the package root:
//   php -S localhost:8767 tools/probes/portone-billing-key-probe.php
// It talks to PortOne with the portone-php clients only, never through the Package.
//
// The observation (no secrets) is written to tools/probes/portone-billing-key-observation.json.
// The issued billing key is kept only in tools/probes/portone-billing-key-state.local.json,
// which is git-ignored, so later steps can use it.

use Cable8mm\PortonePhp\Client\BillingKeyClient;
use Cable8mm\PortonePhp\Client\PaymentClient;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Cable8mm\PortonePhp\Webhook\WebhookVerifier;
use Dotenv\Dotenv;

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// A request through the webhook tunnel carries X-Forwarded-For; it may reach only POST /webhook.
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $path !== '/webhook') {
    http_response_code(403);

    return true;
}

// Leave the browser SDK under /node_modules to the built-in server as a static file.
if (str_starts_with($path, '/node_modules/')) {
    return false;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

const SDK_VERSION = '0.1.13';
const OBSERVATION_PATH = __DIR__.'/portone-billing-key-observation.json';
const STATE_PATH = __DIR__.'/portone-billing-key-state.local.json';
const CHARGE_AMOUNT = 100;
const ORDER_NAME = 'T-045 billing-key probe';
const REDACTED = '[redacted]';

function probeEnv(string $name): string
{
    $value = $_ENV[$name] ?? '';

    return is_string($value) ? $value : '';
}

$apiSecret = probeEnv('PORTONE_API_SECRET');
$storeId = probeEnv('PORTONE_STORE_ID');
$channelKey = probeEnv('PORTONE_E2E_CHANNEL_KEY');
$webhookSecret = probeEnv('PORTONE_WEBHOOK_SECRET');

if ($apiSecret === '' || $storeId === '' || $channelKey === '' || $webhookSecret === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PORTONE_API_SECRET, PORTONE_STORE_ID, PORTONE_E2E_CHANNEL_KEY, and PORTONE_WEBHOOK_SECRET must be set in .env.';

    return;
}

/** @return array<string,mixed> */
function readJsonFile(string $file): array
{
    if (! is_file($file)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : [];
}

function writeJsonFile(string $file, array $data): void
{
    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
    );
}

/** Runs $change on a JSON file under an exclusive lock (webhooks may arrive while a step runs). */
function updateJsonFile(string $file, callable $change): array
{
    $lock = fopen($file.'.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $data = $change(readJsonFile($file));
        writeJsonFile($file, $data);

        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array<string,mixed> */
function observationSkeleton(): array
{
    return [
        'task' => 'T-045',
        'browserSdk' => '@portone/browser-sdk '.SDK_VERSION,
        'channel' => 'NHN KCP test (PORTONE_E2E_CHANNEL_KEY)',
        'client' => 'cable8mm/portone-php v0.7.0 (BillingKeyClient, PaymentClient, WebhookVerifier), not the Package',
        'issueRequest' => ['billingKeyMethod' => 'CARD', 'forceRedirect' => true],
        'chargeRequest' => ['amount' => ['total' => CHARGE_AMOUNT], 'currency' => 'KRW', 'orderName' => ORDER_NAME],
        'secretsRecorded' => false,
        'redaction' => 'billingKey, merchant IDs, channel keys, card numbers and BINs, and customer personal fields are replaced; strings equal to the issued billing key, the store ID, or the probe customer ID are replaced by a marker naming what they matched.',
        'issuances' => [],
        'steps' => [],
        'webhooks' => [],
        // Filled after the live run from the recorded responses above.
        'requirementFields' => [
            'FR-121' => [
                'operation' => 'getBillingKeyInfo after the browser redirect',
                'candidateFields' => ['status', 'customer.id', 'issueId', 'issuedAt'],
                'reliesOn' => null,
                'observedIn' => null,
            ],
            'FR-125' => [
                'operation' => 'verified BillingKey.Issued and BillingKey.Deleted webhooks',
                'candidateFields' => ['type', 'timestamp', 'data.billingKey', 'data.storeId'],
                'reliesOn' => null,
                'observedIn' => null,
            ],
            'FR-126' => [
                'operation' => 'payWithBillingKey request and success response, then getPayment of the charged payment',
                'candidateFields' => ['request: paymentId, billingKey, orderName, amount.total, currency', 'response: payment.pgTxId, payment.paidAt', 'getPayment: status, amount.total, amount.paid, billingKey, receiptUrl'],
                'reliesOn' => null,
                'observedIn' => null,
            ],
            'FR-127' => [
                'operation' => 'payWithBillingKey failure',
                'candidateFields' => ['HTTP status', 'error type', 'message', 'pgCode', 'pgMessage', 'getPayment of the failed payment ID'],
                'reliesOn' => null,
                'observedIn' => null,
            ],
        ],
    ];
}

function recordObservation(callable $change): void
{
    updateJsonFile(OBSERVATION_PATH, static fn (array $observation): array => $change($observation + observationSkeleton()));
}

/** @param array<string,mixed> $record */
function recordStep(string $step, array $record): void
{
    recordObservation(static function (array $observation) use ($step, $record): array {
        $observation['steps'][$step] = ['observedAt' => gmdate(DATE_ATOM)] + $record;

        return $observation;
    });
}

/** @return array<string,mixed> */
function readState(): array
{
    return readJsonFile(STATE_PATH);
}

function writeState(array $state): void
{
    writeJsonFile(STATE_PATH, $state);
    @chmod(STATE_PATH, 0600);
}

const SECRET_KEYS = ['billingKey', 'merchantId', 'pgMerchantId', 'key', 'channelKey', 'number', 'bin', 'pgResponse', 'ipAddress', 'url', 'header'];
const PERSONAL_KEYS = ['name', 'full', 'separated', 'email', 'phoneNumber', 'birthYear', 'birthMonth', 'birthDay', 'gender', 'address', 'zipcode', 'businessRegistrationNumber'];

/** Replaces secret and personal values recursively, keeping every key so the response shape stays visible. */
function redact(mixed $value, array $markers, bool $inCustomer = false): mixed
{
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $isCustomer = $inCustomer || $key === 'customer';
            if (is_string($key) && is_string($item) && isset($markers[$item])) {
                $result[$key] = $markers[$item];
            } elseif (is_string($key) && in_array($key, SECRET_KEYS, true) && $item !== null) {
                $result[$key] = REDACTED;
            } elseif (is_string($key) && $inCustomer && in_array($key, PERSONAL_KEYS, true) && $item !== null) {
                $result[$key] = REDACTED;
            } else {
                $result[$key] = redact($item, $markers, $isCustomer);
            }
        }

        return $result;
    }

    if (is_string($value)) {
        if (isset($markers[$value])) {
            return $markers[$value];
        }
        foreach ($markers as $secret => $marker) {
            if ($secret !== '' && str_contains($value, (string) $secret)) {
                $value = str_replace((string) $secret, $marker, $value);
            }
        }
    }

    return $value;
}

/** @return array<string,string> */
function markers(): array
{
    $state = readState();
    $markers = [];
    if (is_string($state['billingKey'] ?? null) && $state['billingKey'] !== '') {
        $markers[$state['billingKey']] = '[the issued billing key]';
    }
    if (is_string($state['customerId'] ?? null) && $state['customerId'] !== '') {
        $markers[$state['customerId']] = '[the probe customerId]';
    }
    $markers[probeEnv('PORTONE_STORE_ID')] = '[PORTONE_STORE_ID]';
    $markers[probeEnv('PORTONE_E2E_CHANNEL_KEY')] = REDACTED;

    return $markers;
}

/** @return array<string,mixed> */
function exceptionRecord(Throwable $exception): array
{
    $record = ['result' => 'error', 'exception' => $exception::class, 'message' => redact($exception->getMessage(), markers())];
    if ($exception instanceof PortOneException) {
        $body = $exception->responseBody === null ? null : json_decode($exception->responseBody, true);
        $record['httpStatus'] = $exception->status;
        $record['errorType'] = $exception->errorType;
        $record['responseBody'] = is_array($body) ? redact($body, markers()) : null;
    }

    return $record;
}

/** @return array<string,mixed> */
function attempt(callable $call): array
{
    try {
        return ['result' => 'success'] + $call();
    } catch (Throwable $exception) {
        return exceptionRecord($exception);
    }
}

function newId(string $prefix): string
{
    return $prefix.bin2hex(random_bytes(16));
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$billingKeys = new BillingKeyClient($apiSecret);
$payments = new PaymentClient($apiSecret);

$billingKeyInfo = static function (string $billingKey) use ($billingKeys, $storeId): array {
    return attempt(static function () use ($billingKeys, $billingKey, $storeId): array {
        $info = $billingKeys->getBillingKeyInfo($billingKey, $storeId);
        $state = readState();

        return [
            'variant' => $info->variant,
            'knownVariant' => $info->knownVariant,
            'customerIdMatchesIssuance' => ($info->data['customer']['id'] ?? null) === ($state['customerId'] ?? false),
            'issueIdMatchesIssuance' => ($info->data['issueId'] ?? null) === ($state['issueId'] ?? false),
            'topLevelKeys' => array_keys($info->data),
            'data' => redact($info->data, markers()),
        ];
    });
};

$lookUpPayment = static function (string $paymentId) use ($payments, $storeId): array {
    return attempt(static function () use ($payments, $paymentId, $storeId): array {
        $payment = $payments->getPayment($paymentId, $storeId);

        return [
            'variant' => $payment->variant,
            'totalAmount' => $payment->totalAmount(),
            'paidAmount' => $payment->paidAmount(),
            'cancelledAmount' => $payment->cancelledAmount(),
            'receiptUrlPresent' => $payment->receiptUrl() !== null,
            'billingKeyIsTheIssuedKey' => ($payment->data['billingKey'] ?? null) === (readState()['billingKey'] ?? false),
            'topLevelKeys' => array_keys($payment->data),
            'data' => redact($payment->data, markers()),
        ];
    });
};

/** @param array<string,mixed> $extra */
$charge = static function (string $billingKey, string $paymentId, int $amount, array $extra = []) use ($payments, $storeId): array {
    $request = ['paymentId' => $paymentId, 'orderName' => ORDER_NAME, 'amount' => ['total' => $amount], 'currency' => 'KRW'] + $extra;
    $result = attempt(static function () use ($payments, $billingKey, $paymentId, $amount, $extra, $storeId): array {
        $response = $payments->payWithBillingKey(
            paymentId: $paymentId,
            billingKey: $billingKey,
            orderName: ORDER_NAME,
            customer: $extra['customer'] ?? null,
            amount: ['total' => $amount],
            currency: 'KRW',
            installmentMonth: $extra['installmentMonth'] ?? null,
            storeId: $storeId,
        );

        return ['response' => ['payment' => ['pgTxId' => $response->payment->pgTxId, 'paidAt' => $response->payment->paidAt]]];
    });

    return ['request' => redact($request, markers())] + $result;
};

$cancel = static function (string $paymentId, string $reason) use ($payments, $storeId): array {
    return attempt(static function () use ($payments, $paymentId, $reason, $storeId): array {
        $response = $payments->cancelPayment(paymentId: $paymentId, reason: $reason, storeId: $storeId);

        // CancelPaymentResponse leaves OperationResponse::$data uninitialized; read its typed cancellation.
        return ['cancellation' => redact($response->cancellation->data, markers())];
    });
};

$probeCustomer = static function (string $customerId): array {
    return ['id' => $customerId, 'name' => ['full' => 'Probe Tester'], 'phoneNumber' => '01000000000', 'email' => 'probe@example.com'];
};

$requireKey = static function (): ?string {
    $billingKey = readState()['billingKey'] ?? null;

    return is_string($billingKey) && $billingKey !== '' ? $billingKey : null;
};

// Step b: getBillingKeyInfo, payWithBillingKey for KRW 100, and getPayment of the charge.
$runIssuedSteps = static function (string $billingKey) use ($billingKeyInfo, $charge, $lookUpPayment, $probeCustomer): array {
    $info = $billingKeyInfo($billingKey);
    recordStep('billingKeyInfo', $info);

    // FR-126 sends only the Payment ID, order name, amount, and currency.
    $paymentId = newId('bkpay');
    $minimal = $charge($billingKey, $paymentId, CHARGE_AMOUNT);
    $chargeRecord = ['minimalRequest' => $minimal];
    if ($minimal['result'] !== 'success') {
        // Only when the minimal request is refused: retry once with the issuance customer.
        $paymentId = newId('bkpay');
        $chargeRecord['withCustomerRequest'] = $charge($billingKey, $paymentId, CHARGE_AMOUNT, ['customer' => $probeCustomer((string) readState()['customerId'])]);
    }
    $charged = ($chargeRecord['withCustomerRequest'] ?? $minimal)['result'] === 'success';
    recordStep('charge', $chargeRecord);

    $lookup = null;
    if ($charged) {
        writeState(['chargedPaymentId' => $paymentId] + readState());
        $lookup = $lookUpPayment($paymentId);
        recordStep('chargedPaymentLookup', $lookup);
    }

    return ['billingKeyInfo' => $info, 'charge' => $chargeRecord, 'chargedPaymentLookup' => $lookup];
};

if ($path === '/webhook') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);

        return;
    }
    $body = (string) file_get_contents('php://input');
    $header = static fn (string $name): ?string => is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : null;
    try {
        $webhook = (new WebhookVerifier($webhookSecret))->verify(
            $body,
            $header('HTTP_WEBHOOK_ID'),
            $header('HTTP_WEBHOOK_SIGNATURE'),
            $header('HTTP_WEBHOOK_TIMESTAMP'),
        );
    } catch (Throwable $exception) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Webhook verification failed.';

        return;
    }

    $decoded = json_decode($body, true);
    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $record = [
        'receivedAt' => gmdate(DATE_ATOM),
        'headers' => ['webhook-id' => $header('HTTP_WEBHOOK_ID'), 'webhook-timestamp' => $header('HTTP_WEBHOOK_TIMESTAMP')],
        'webhookClass' => $webhook::class,
        'type' => $decoded['type'] ?? null,
        'dataKeys' => array_keys($data),
        'billingKeyIsTheIssuedKey' => isset($data['billingKey']) ? $data['billingKey'] === (readState()['billingKey'] ?? false) : null,
        'body' => redact($decoded, markers()),
    ];
    recordObservation(static function (array $observation) use ($record): array {
        $observation['webhooks'][] = $record;

        return $observation;
    });
    http_response_code(200);

    return;
}

if ($path === '/prepare' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Like EL-071: the Issue ID and customer ID exist before the browser opens the PG window.
    $existing = $requireKey();
    if ($existing !== null && empty(readState()['deleted'])) {
        jsonResponse(['error' => 'A billing key from an earlier issuance is not deleted yet; run the delete step first.'], 409);

        return;
    }
    $state = ['issueId' => newId('bk'), 'customerId' => newId('probe-'), 'preparedAt' => gmdate(DATE_ATOM)];
    writeState($state);
    jsonResponse(['issueId' => $state['issueId'], 'customerId' => $state['customerId'], 'redirectUrl' => 'http://'.$_SERVER['HTTP_HOST'].'/redirect']);

    return;
}

if ($path === '/observe' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // The SDK promise result, when the SDK resolves instead of redirecting.
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($body)) {
        // redact() replaces the billingKey value; the error carries no secret.
        $record = ['source' => 'requestIssueBillingKey promise', 'observedAt' => gmdate(DATE_ATOM), 'result' => redact($body['result'] ?? null, markers()), 'error' => $body['error'] ?? null];
        recordObservation(static function (array $observation) use ($record): array {
            $observation['issuances'][] = $record;

            return $observation;
        });
    }
    http_response_code(204);

    return;
}

if ($path === '/redirect') {
    $state = readState();
    parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);
    $billingKey = is_string($query['billingKey'] ?? null) ? $query['billingKey'] : '';
    $failed = isset($query['code']);

    $redactedQuery = $query;
    if (array_key_exists('billingKey', $redactedQuery)) {
        $redactedQuery['billingKey'] = REDACTED;
    }
    $issuance = [
        'source' => 'redirect query received by redirectUrl',
        'observedAt' => gmdate(DATE_ATOM),
        'queryKeys' => array_keys($query),
        'query' => redact($redactedQuery, markers()),
        'outcome' => $failed ? 'failure (code present)' : ($billingKey !== '' ? 'success (billingKey without code)' : 'neither code nor billingKey'),
    ];

    $issued = null;
    if (! $failed && $billingKey !== '') {
        writeState(['billingKey' => $billingKey, 'issuedRedirectAt' => gmdate(DATE_ATOM)] + $state);
    }
    recordObservation(static function (array $observation) use ($issuance): array {
        $observation['issuances'][] = $issuance;

        return $observation;
    });
    if (! $failed && $billingKey !== '') {
        $issued = $runIssuedSteps($billingKey);
    }

    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Billing-key redirect recorded</title>';
    echo '<h1>Redirect recorded: '.htmlspecialchars($issuance['outcome']).'</h1><pre>'
        .htmlspecialchars(json_encode(['issuance' => $issuance, 'afterIssuance' => $issued], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        .'</pre><p><a href="/">Back to the probe</a></p>';

    return;
}

if (preg_match('#^/step/([a-z-]+)$#', $path, $match) === 1) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);

        return;
    }
    $billingKey = $requireKey();
    if ($billingKey === null) {
        jsonResponse(['error' => 'No issued billing key in the local state; issue one in the browser first.'], 409);

        return;
    }
    $state = readState();

    switch ($match[1]) {
        case 'issued':
            // Re-runs step b (another KRW 100 charge) when the redirect run needs repeating.
            jsonResponse($runIssuedSteps($billingKey));

            return;

        case 'failure':
            // Step c: provoke a PG refusal on the live key without another card entry.
            $candidates = [
                'amountOneWon' => ['amount' => 1, 'extra' => [], 'why' => 'KRW 1 is below the card minimum that PGs accept'],
                'installmentOnSmallAmount' => ['amount' => CHARGE_AMOUNT, 'extra' => ['installmentMonth' => 3], 'why' => 'Card installments are not allowed below KRW 50,000'],
            ];
            $results = [];
            foreach ($candidates as $name => $candidate) {
                $paymentId = newId('bkfail');
                $result = ['why' => $candidate['why']] + $charge($billingKey, $paymentId, $candidate['amount'], $candidate['extra']);
                $result['paymentLookup'] = $lookUpPayment($paymentId);
                if ($result['result'] === 'success') {
                    // The PG accepted it after all: cancel at once so no test charge is left.
                    $result['cleanupCancellation'] = $cancel($paymentId, 'T-045 probe cleanup');
                }
                $results[$name] = $result;
            }
            recordStep('chargeFailure', $results);
            jsonResponse($results);

            return;

        case 'cancel':
            // Step d (first half): cancel the KRW 100 test charge.
            $paymentId = $state['chargedPaymentId'] ?? null;
            if (! is_string($paymentId)) {
                jsonResponse(['error' => 'No charged payment in the local state.'], 409);

                return;
            }
            $record = ['cancellation' => $cancel($paymentId, 'T-045 probe cancellation'), 'lookupAfterCancellation' => $lookUpPayment($paymentId)];
            recordStep('chargeCancellation', $record);
            jsonResponse($record);

            return;

        case 'delete':
            // Step d (second half): deleteBillingKey, then look the deleted key up.
            $record = [
                // DeleteBillingKeyResponse leaves OperationResponse::$data uninitialized; read its typed deletedAt.
                'deletion' => attempt(static function () use ($billingKeys, $billingKey, $storeId): array {
                    $response = $billingKeys->deleteBillingKey($billingKey, 'T-045 probe deletion', null, null, $storeId);

                    return ['model' => $response::class, 'deletedAt' => $response->deletedAt];
                }),
            ];
            $record['billingKeyInfoAfterDeletion'] = $billingKeyInfo($billingKey);
            if ($record['deletion']['result'] === 'success') {
                writeState(['deleted' => true, 'deletedAt' => gmdate(DATE_ATOM)] + $state);
            }
            recordStep('deletion', $record);
            jsonResponse($record);

            return;

        case 'after-delete':
            // A charge and a second deletion of the deleted key (a PortOne-side refusal, not a PG one).
            $record = [
                'chargeDeletedKey' => $charge($billingKey, $paymentId = newId('bkdel'), CHARGE_AMOUNT),
            ];
            $record['chargeDeletedKey']['paymentLookup'] = $lookUpPayment($paymentId);
            $record['deleteAgain'] = attempt(static function () use ($billingKeys, $billingKey, $storeId): array {
                $response = $billingKeys->deleteBillingKey($billingKey, 'T-045 probe second deletion', null, null, $storeId);

                return ['model' => $response::class, 'deletedAt' => $response->deletedAt];
            });
            recordStep('afterDeletion', $record);
            jsonResponse($record);

            return;
    }

    jsonResponse(['error' => 'Unknown step.'], 404);

    return;
}

if ($path === '/observation') {
    jsonResponse(readJsonFile(OBSERVATION_PATH));

    return;
}

if ($path !== '/') {
    http_response_code(404);

    return;
}

$state = readState();
$summary = [
    'issueId' => $state['issueId'] ?? null,
    'billingKeyIssued' => isset($state['billingKey']),
    'chargedPaymentId' => $state['chargedPaymentId'] ?? null,
    'deleted' => (bool) ($state['deleted'] ?? false),
];

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing-key probe</title>
</head>
<body>
    <main>
        <h1>PortOne billing-key probe (T-045)</h1>
        <p>Local state: <code id="summary"><?= htmlspecialchars(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></code></p>
        <ol>
            <li><button id="issue" type="button">1. Register a card (issue billing key)</button> — complete the card registration in the KCP window. The redirect then runs getBillingKeyInfo, a KRW 100 charge, and its lookup.</li>
            <li><button data-step="failure" type="button">2. Provoke charge failures</button></li>
            <li><button data-step="cancel" type="button">3. Cancel the KRW 100 charge</button></li>
            <li><button data-step="delete" type="button">4. Delete the billing key</button></li>
            <li><button data-step="after-delete" type="button">5. Charge and delete the deleted key</button></li>
        </ol>
        <p><a href="/observation">Recorded observation</a></p>
        <pre id="status" aria-live="polite"></pre>
    </main>
    <script id="portone-config" type="application/json"><?= json_encode(
        ['storeId' => $storeId, 'channelKey' => $channelKey],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script type="module">
        import PortOne from "/node_modules/@portone/browser-sdk/dist/v2.js";

        const config = JSON.parse(document.querySelector("#portone-config").textContent);
        const status = document.querySelector("#status");

        // Prepared on page load: an await between the click and the SDK call loses the
        // user activation, and KCP then reports "[3017] 팝업 차단 상태".
        const prepared = await fetch("/prepare", { method: "POST" });
        const values = await prepared.json();
        if (!prepared.ok) {
            status.textContent = JSON.stringify(values, null, 2);
        }

        document.querySelector("#issue").addEventListener("click", async () => {
            if (!prepared.ok) {
                return;
            }
            status.textContent = `Requesting issuance ${values.issueId}…`;
            let result = null;
            let error = null;
            try {
                result = await PortOne.requestIssueBillingKey({
                    storeId: config.storeId,
                    channelKey: config.channelKey,
                    billingKeyMethod: "CARD",
                    issueId: values.issueId,
                    issueName: "T-045 probe",
                    customer: {
                        customerId: values.customerId,
                        fullName: "Probe Tester",
                        phoneNumber: "01000000000",
                        email: "probe@example.com",
                    },
                    redirectUrl: values.redirectUrl,
                    forceRedirect: true,
                });
            } catch (e) {
                error = { name: e?.name ?? null, code: e?.code ?? null, message: String(e?.message ?? e) };
            }
            const shown = result && "billingKey" in result ? { ...result, billingKey: "[redacted]" } : result;
            status.textContent = JSON.stringify({ result: shown, error }, null, 2);
            await fetch("/observe", { method: "POST", body: JSON.stringify({ result, error }) });
        });

        document.querySelectorAll("[data-step]").forEach((button) => {
            button.addEventListener("click", async () => {
                status.textContent = `Running ${button.dataset.step}…`;
                const response = await fetch(`/step/${button.dataset.step}`, { method: "POST" });
                status.textContent = await response.text();
            });
        });
    </script>
</body>
</html>
