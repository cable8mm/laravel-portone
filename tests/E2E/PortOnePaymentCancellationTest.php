<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\PortonePhp\Client\PortOneClient;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * Observes PortOne cancellation itself through cable8mm/portone-php; the
 * browser only produces the two paid payments the test then cancels.
 */
class PortOnePaymentCancellationTest extends TestCase
{
    public function test_live_cancellation_behavior(): void
    {
        $apiSecret = config('portone.api_secret');
        self::assertIsString($apiSecret);

        // Each run cancels the two payments it has the human pay, so the test
        // needs no paid payment left over from an earlier run.
        $user = UserFactory::new()->create();
        $fullPaymentId = $user->preparePayment(100, 'PortOne full cancellation probe')->payment_id;
        $partialPaymentId = $user->preparePayment(100, 'PortOne partial cancellation probe')->payment_id;

        $this->browse(function (Browser $browser) use ($user, $fullPaymentId, $partialPaymentId): void {
            foreach ([$fullPaymentId, $partialPaymentId] as $number => $paymentId) {
                $payment = $user->findPayment($paymentId);
                self::assertNotNull($payment);
                $this->payInBrowser(
                    $browser,
                    $user,
                    $payment,
                    'Complete the 100 KRW PortOne test payment ('.($number + 1).' of 2) in the browser window.',
                );
            }
        });

        $client = new PortOneClient($apiSecret);
        $fullPaymentBefore = $client->payment->getPayment($fullPaymentId);
        $partialPaymentBefore = $client->payment->getPayment($partialPaymentId);
        $fullAmount = $this->totalAmount($fullPaymentBefore->data);
        $partialAmount = $this->totalAmount($partialPaymentBefore->data);
        self::assertSame('PAID', $fullPaymentBefore->variant);
        self::assertSame('PAID', $partialPaymentBefore->variant);
        self::assertSame(100, $fullAmount);
        self::assertSame($fullAmount, $partialAmount);
        self::assertNotSame($fullPaymentId, $partialPaymentId);

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php',
            'environment' => 'PortOne test channel',
            'publishedStubs' => true,
            'payments' => [
                'fullCancellation' => [
                    'paymentId' => $fullPaymentId,
                    'initialStatus' => $fullPaymentBefore->variant,
                    'initialAmount' => $fullAmount,
                ],
                'partialCancellation' => [
                    'paymentId' => $partialPaymentId,
                    'initialStatus' => $partialPaymentBefore->variant,
                    'initialAmount' => $partialAmount,
                ],
            ],
            'secretsRecorded' => false,
        ];

        try {
            $fullCancellation = $this->cancel($client, $fullPaymentId, null);
            $observation['fullCancellation'] = $fullCancellation;
            self::assertSame('success', $fullCancellation['result']);
            self::assertSame('SUCCEEDED', $fullCancellation['status']);
            self::assertSame($fullAmount, $fullCancellation['amount']);

            $alreadyCancelled = $this->cancel($client, $fullPaymentId, null);
            $observation['alreadyCancelled'] = $alreadyCancelled;
            self::assertSame('error', $alreadyCancelled['result']);

            $partialCancellation = $this->cancel($client, $partialPaymentId, 30);
            $observation['partialCancellation'] = $partialCancellation;
            self::assertSame('success', $partialCancellation['result']);
            self::assertSame('SUCCEEDED', $partialCancellation['status']);
            self::assertSame(30, $partialCancellation['amount']);

            $partialPaymentAfter = $client->payment->getPayment($partialPaymentId);
            $partialState = $this->paymentState($partialPaymentAfter->variant, $partialPaymentAfter->data);
            $observation['partialPaymentAfterFirstCancellation'] = $partialState;
            self::assertSame('PARTIAL_CANCELLED', $partialPaymentAfter->variant);
            self::assertSame(30, $partialState['cancelledAmount']);
            self::assertSame(70, $partialState['cancellableAmount']);

            $overRemaining = $this->cancel($client, $partialPaymentId, 71);
            $observation['amountOverRemaining'] = $overRemaining;
            self::assertSame('error', $overRemaining['result']);

            $remainingCancellation = $this->cancel($client, $partialPaymentId, null);
            $observation['remainingCancellation'] = $remainingCancellation;
            self::assertSame('success', $remainingCancellation['result']);
            self::assertSame('SUCCEEDED', $remainingCancellation['status']);
            self::assertSame(70, $remainingCancellation['amount']);

            $partialPaymentAfterRemaining = $client->payment->getPayment($partialPaymentId);
            $finalState = $this->paymentState($partialPaymentAfterRemaining->variant, $partialPaymentAfterRemaining->data);
            $observation['partialPaymentAfterRemainingCancellation'] = $finalState;
            self::assertSame('CANCELLED', $partialPaymentAfterRemaining->variant);
            self::assertSame(100, $finalState['cancelledAmount']);
            self::assertSame(0, $finalState['cancellableAmount']);
        } finally {
            $this->recordObservation('portone-payment-cancellation-observation.json', $observation);
        }
    }

    /**
     * @return array{result: string, status?: string|null, amount?: int|null, exception?: string, httpStatus?: int|null, errorType?: string|null}
     */
    private function cancel(PortOneClient $client, string $paymentId, ?int $amount): array
    {
        try {
            $response = $client->payment->cancelPayment(
                paymentId: $paymentId,
                amount: $amount,
                reason: 'PortOne Laravel package live cancellation probe',
            );

            return [
                'result' => 'success',
                'status' => $response->cancellation->data['status'] ?? null,
                'amount' => $response->cancellation->data['totalAmount'] ?? null,
            ];
        } catch (PortOneException $exception) {
            return [
                'result' => 'error',
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function totalAmount(array $data): ?int
    {
        $amount = $data['amount'] ?? null;

        if (! is_array($amount)) {
            return null;
        }

        return is_int($amount['total'] ?? null) ? $amount['total'] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, cancelledAmount: int|null, cancellableAmount: int|null}
     */
    private function paymentState(string $status, array $data): array
    {
        $cancellations = $data['cancellations'] ?? [];
        $cancelledAmount = 0;

        if (is_array($cancellations)) {
            foreach ($cancellations as $cancellation) {
                if (is_array($cancellation) && is_int($cancellation['totalAmount'] ?? null)) {
                    $cancelledAmount += $cancellation['totalAmount'];
                }
            }
        }

        $totalAmount = $this->totalAmount($data);

        return [
            'status' => $status,
            'cancelledAmount' => $cancelledAmount,
            'cancellableAmount' => $totalAmount === null ? null : $totalAmount - $cancelledAmount,
        ];
    }
}
