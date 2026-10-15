<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\PortOneGateway;
use PHPUnit\Framework\TestCase;

/**
 * The PortOne lookup response mapped to a gateway result (T-032).
 *
 * The PAID response is the live response recorded by T-030
 * (tests/E2E/portone-payment-command-observation.json). The cancelled amounts are the values
 * recorded by T-010 (tests/E2E/portone-payment-cancellation-observation.json): PARTIAL_CANCELLED
 * with 30 cancelled out of 100, then CANCELLED with 100.
 */
class GatewayPaymentMappingTest extends TestCase
{
    public function test_recorded_paid_response_reports_total_and_zero_cancelled(): void
    {
        $recording = json_decode((string) file_get_contents(dirname(__DIR__).'/E2E/portone-payment-command-observation.json'), true);
        $response = $recording['redactedResponse'];

        $result = $this->map($response['status'], $response);

        $this->assertSame('PAID', $result->status);
        $this->assertSame(100, $result->totalAmount);
        $this->assertSame(0, $result->cancelledAmount);
        $this->assertTrue($result->found);
    }

    public function test_cancelled_amounts_are_read_from_amount_cancelled(): void
    {
        $partial = $this->map('PARTIAL_CANCELLED', ['amount' => ['total' => 100, 'cancelled' => 30]]);
        $full = $this->map('CANCELLED', ['amount' => ['total' => 100, 'cancelled' => 100]]);

        $this->assertSame([100, 30], [$partial->totalAmount, $partial->cancelledAmount]);
        $this->assertSame([100, 100], [$full->totalAmount, $full->cancelledAmount]);
    }

    public function test_missing_amounts_are_null(): void
    {
        $result = $this->map('READY', []);

        $this->assertNull($result->totalAmount);
        $this->assertNull($result->cancelledAmount);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function map(string $status, array $data): GatewayPayment
    {
        return (new class extends PortOneGateway
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function map(string $status, array $data): GatewayPayment
            {
                return $this->gatewayPayment($status, $data);
            }
        })->map($status, $data);
    }
}
