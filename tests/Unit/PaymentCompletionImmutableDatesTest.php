<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\GatewayPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Runs every test of PaymentCompletionTest with immutable dates, as an Application that calls
 * Date::use(CarbonImmutable::class) does (the Laravel starter kit default).
 */
class PaymentCompletionImmutableDatesTest extends PaymentCompletionTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Date::use(CarbonImmutable::class);
    }

    protected function tearDown(): void
    {
        Date::useDefault();

        parent::tearDown();
    }

    public function test_completion_stores_paid_at_as_an_immutable_date(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('PAID', 100));

        $completed = $billable->completePayment($payment->payment_id);

        $this->assertSame('PAID', $completed->status);
        $this->assertInstanceOf(CarbonImmutable::class, $completed->paid_at);
        $this->assertInstanceOf(CarbonImmutable::class, $payment->fresh()->paid_at);
    }
}
