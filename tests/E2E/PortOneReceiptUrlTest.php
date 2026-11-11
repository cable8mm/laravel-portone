<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneGateway;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

/**
 * T-042: completion, cancellation sync, and duplicate sync store the receiptUrl of the live PortOne lookup
 * (FR-113, FR-114, AC-098).
 *
 * No human payment is needed: the test reuses the live 100 KRW test payments paid by T-041
 * (tests/E2E/portone-duplicate-payment-observation.json). The PAID one is completed through a local PENDING
 * record, cancelled at PortOne through the Package's gateway, and synchronized; the one already CANCELLED is
 * synchronized from a local DUPLICATE record. Only whether each URL is non-empty and its host are recorded.
 */
class PortOneReceiptUrlTest extends TestCase
{
    private const OBSERVATION = 'portone-receipt-url-observation.json';

    private const DUPLICATE_OBSERVATION = 'portone-duplicate-payment-observation.json';

    public function test_completion_and_cancellation_sync_store_the_live_receipt_url(): void
    {
        $recorded = json_decode((string) file_get_contents(__DIR__.'/'.self::DUPLICATE_OBSERVATION), true, 512, JSON_THROW_ON_ERROR);
        $paidPaymentId = $recorded['paid']['paymentId'];
        $cancelledPaymentId = $recorded['duplicate']['paymentId'];

        $gateway = $this->app->make(PortOneGateway::class);
        $operations = $this->app->make(PaymentOperations::class);
        $user = UserFactory::new()->create();

        // Completion (FR-023) of a payment PortOne reports PAID.
        $paidLookup = $gateway->lookup($paidPaymentId);
        self::assertSame(['PAID', 100], [$paidLookup->status, $paidLookup->totalAmount], 'The reused payment is no longer PAID at PortOne.');
        $paid = $this->localRecord($user, $paidPaymentId, 'PENDING');
        $completed = $user->completePayment($paidPaymentId);
        self::assertSame('PAID', $completed->status);
        self::assertNotEmpty($completed->receipt_url);
        self::assertSame($paidLookup->receiptUrl, $completed->fresh()->receipt_url);

        // Cancellation at PortOne through the Package, then the cancellation sync (FR-083).
        $gateway->cancel($paidPaymentId, 'PortOne receipt URL probe', null);
        $cancelledLookup = $gateway->lookup($paidPaymentId);
        self::assertSame(['CANCELLED', 100], [$cancelledLookup->status, $cancelledLookup->cancelledAmount]);
        $synced = $operations->syncByPaymentId($paidPaymentId);
        self::assertSame(['CANCELLED', 100], [$synced->status, $synced->cancelled_amount]);
        self::assertNotEmpty($synced->receipt_url);
        self::assertSame($cancelledLookup->receiptUrl, $synced->fresh()->receipt_url);

        // Duplicate sync (FR-083 for DUPLICATE) of a payment already CANCELLED at PortOne.
        $duplicateLookup = $gateway->lookup($cancelledPaymentId);
        self::assertSame(['CANCELLED', 100], [$duplicateLookup->status, $duplicateLookup->cancelledAmount]);
        $this->localRecord($user, $cancelledPaymentId, 'DUPLICATE', paidAmount: 100);
        $duplicate = $operations->syncByPaymentId($cancelledPaymentId);
        self::assertSame(['DUPLICATE_CANCELLED', 100], [$duplicate->status, $duplicate->cancelled_amount]);
        self::assertNotEmpty($duplicate->receipt_url);
        self::assertSame($duplicateLookup->receiptUrl, $duplicate->fresh()->receipt_url);

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'source' => 'live 100 KRW test payments paid by T-041 ('.self::DUPLICATE_OBSERVATION.'); no new payment',
            'paid' => [
                'paymentId' => $paidPaymentId,
                'portOneStatus' => $paidLookup->status,
                'receiptUrl' => $this->describe($paidLookup->receiptUrl),
                'recordStatus' => $completed->status,
                'recordReceiptUrlEqualsLookup' => $completed->receipt_url === $paidLookup->receiptUrl,
            ],
            'cancelledThroughPackage' => [
                'paymentId' => $paidPaymentId,
                'portOneStatusAfterCancel' => $cancelledLookup->status,
                'portOneCancelledAmount' => $cancelledLookup->cancelledAmount,
                'receiptUrl' => $this->describe($cancelledLookup->receiptUrl),
                'receiptUrlUnchangedByCancellation' => $cancelledLookup->receiptUrl === $paidLookup->receiptUrl,
                'recordStatus' => $synced->status,
                'recordReceiptUrlEqualsLookup' => $synced->receipt_url === $cancelledLookup->receiptUrl,
            ],
            'alreadyCancelled' => [
                'paymentId' => $cancelledPaymentId,
                'portOneStatus' => $duplicateLookup->status,
                'receiptUrl' => $this->describe($duplicateLookup->receiptUrl),
                'recordStatusBeforeSync' => 'DUPLICATE',
                'recordStatus' => $duplicate->status,
                'recordReceiptUrlEqualsLookup' => $duplicate->receipt_url === $duplicateLookup->receiptUrl,
            ],
            'urlsRedacted' => true,
            'secretsRecorded' => false,
        ]);

        self::assertSame($paid->getKey(), $synced->getKey());
    }

    private function localRecord(User $user, string $paymentId, string $status, ?int $paidAmount = null): Payment
    {
        return Payment::query()->create([
            'payment_id' => $paymentId,
            'billable_type' => $user->getMorphClass(),
            'billable_id' => (string) $user->getKey(),
            'customer_id' => $user->createOrGetPortOneCustomerId(),
            'order_name' => 'PortOne receipt URL probe',
            'amount' => 100,
            'status' => $status,
            'cancelled_amount' => 0,
            'paid_amount' => $paidAmount,
            'paid_at' => $paidAmount === null ? null : now(),
        ]);
    }

    /**
     * @return array{nonEmpty: bool, host: string|null}
     */
    private function describe(?string $url): array
    {
        $host = $url === null ? null : parse_url($url, PHP_URL_HOST);

        return ['nonEmpty' => $url !== null && $url !== '', 'host' => is_string($host) ? $host : null];
    }
}
