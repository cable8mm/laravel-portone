<?php

declare(strict_types=1);

use Cable8mm\PortonePhp\Client\PortOneClient;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

$apiSecret = $_ENV['PORTONE_API_SECRET'] ?? '';

if ($apiSecret === '') {
    fwrite(STDERR, "PORTONE_API_SECRET must be set in the local .env file.\n");
    exit(1);
}

$browserObservation = json_decode(
    file_get_contents(__DIR__.'/portone-browser-sdk-observation.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$paidPaymentId = $browserObservation['completedPayment']['paymentId'] ?? null;
$unpaidPaymentId = $browserObservation['closedPayment']['paymentId'] ?? null;
$portOne = new PortOneClient($apiSecret);

/** @return array<string, int|string|null> */
$errorSummary = static function (Throwable $exception): array {
    return [
        'exception' => $exception::class,
        'httpStatus' => $exception instanceof PortOneException ? $exception->status : null,
        'errorType' => $exception instanceof PortOneException ? $exception->errorType : null,
    ];
};

/** @return array<string, int|string|null> */
$lookup = static function (string $paymentId) use ($portOne, $errorSummary): array {
    try {
        $payment = $portOne->payment->getPayment($paymentId);
        $amount = $payment->data['amount'] ?? null;
        if (is_array($amount)) {
            $amount = $amount['total'] ?? null;
        }

        return [
            'result' => 'success',
            'status' => $payment->variant,
            'totalAmount' => is_int($amount) ? $amount : null,
        ];
    } catch (Throwable $exception) {
        return ['result' => 'error'] + $errorSummary($exception);
    }
};

if (! is_string($paidPaymentId) || ! is_string($unpaidPaymentId)) {
    fwrite(STDERR, "The saved browser SDK observation is missing payment IDs.\n");
    exit(1);
}

$unknownPaymentId = 'probe-unknown-'.bin2hex(random_bytes(12));

$results = [
    'recordedAt' => gmdate(DATE_ATOM),
    'client' => 'cable8mm/portone-php',
    'requestMode' => 'PortOneClient default RequestExecutor; no custom executor or base URL',
    'defaultApiBaseUrl' => 'https://api.portone.io',
    'paidPayment' => [
        'paymentId' => $paidPaymentId,
        'expectedAmount' => $browserObservation['completedPayment']['totalAmount'],
        'lookup' => $lookup($paidPaymentId),
    ],
    'unpaidPayment' => [
        'paymentId' => $unpaidPaymentId,
        'lookup' => $lookup($unpaidPaymentId),
    ],
    'unknownPayment' => [
        'lookup' => $lookup($unknownPaymentId),
    ],
];

try {
    (new PortOneClient('invalid-live-probe-credential'))->payment->getPayment($paidPaymentId);
    $results['invalidApiSecret'] = ['result' => 'unexpected success'];
} catch (Throwable $exception) {
    $results['invalidApiSecret'] = ['result' => 'error'] + $errorSummary($exception);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
