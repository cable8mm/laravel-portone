<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\UnauthorizedException;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Expiry and pruning of abandoned PENDING payments (T-022).
 *
 * Gateway results are traced to tools/probes/portone-payment-lookup-observation.json (T-003)
 * and tools/probes/portone-abandoned-payment-observation.json (T-021): PAID 100,
 * READY 100, FAILED 100, and PAYMENT_NOT_FOUND (UnknownException, HTTP 404).
 */
class PaymentExpiryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
    }

    public function test_command_queues_one_check_per_stale_pending_payment_without_contacting_portone(): void
    {
        Queue::fake();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method($this->anything());
        $billable = $this->billable(101);
        $stale = $this->pending($billable, minutesOld: 1441);
        $this->pending($billable, minutesOld: 1439);
        $paid = $this->pending($billable, minutesOld: 2000);
        $paid->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();

        $this->artisan('portone:expire-pending')->assertExitCode(0);

        Queue::assertPushed(ExpirePendingPayment::class, 1);
        Queue::assertPushed(ExpirePendingPayment::class, fn (ExpirePendingPayment $job): bool => $job->paymentId === $stale->payment_id
            && $job->billableType === $billable->getMorphClass()
            && $job->billableId === '101');
    }

    public function test_command_uses_the_configured_age(): void
    {
        Queue::fake();
        config(['portone.pending_expire_after' => 30]);
        $billable = $this->billable(101);
        $this->pending($billable, minutesOld: 31);
        $this->pending($billable, minutesOld: 29);

        $this->artisan('portone:expire-pending')->assertExitCode(0);

        Queue::assertPushed(ExpirePendingPayment::class, 1);
    }

    public function test_job_does_nothing_for_a_payment_that_is_no_longer_pending_or_gone(): void
    {
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method($this->anything());
        $billable = $this->billable(101);
        $paid = $this->pending($billable, minutesOld: 2000);
        $paid->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();

        $this->runJob($billable, $paid->payment_id);
        $this->runJob($billable, 'deleted-payment-id');

        $this->assertSame('PAID', $paid->fresh()->status);
    }

    public function test_job_marks_a_payment_paid_at_portone_as_paid(): void
    {
        $billable = $this->billable(101);
        $payment = $this->pending($billable, minutesOld: 2000, amount: 100);
        $this->gatewayMock()->expects($this->once())->method('lookupForExpiry')
            ->with($payment->payment_id)->willReturn(new GatewayPayment('PAID', 100));

        $this->runJob($billable, $payment->payment_id);

        $payment->refresh();
        $this->assertSame('PAID', $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    /**
     * @return iterable<string, array{GatewayPayment}>
     */
    public static function notPaidAtTheTimeOfTheCheck(): iterable
    {
        yield 'window closed (FAILED)' => [new GatewayPayment('FAILED', 100)];
        yield 'window open (READY)' => [new GatewayPayment('READY', 100)];
        yield 'window never opened (PAYMENT_NOT_FOUND)' => [new GatewayPayment('PAYMENT_NOT_FOUND', null, found: false)];
    }

    #[DataProvider('notPaidAtTheTimeOfTheCheck')]
    public function test_job_expires_a_payment_not_paid_at_the_time_of_the_check(GatewayPayment $result): void
    {
        $billable = $this->billable(101);
        $payment = $this->pending($billable, minutesOld: 2000, amount: 100);
        $this->gatewayMock()->expects($this->once())->method('lookupForExpiry')->willReturn($result);

        $this->runJob($billable, $payment->payment_id);

        $payment->refresh();
        $this->assertSame('EXPIRED', $payment->status);
        $this->assertSame(0, $payment->cancelled_amount);
        $this->assertNull($payment->paid_at);
    }

    public function test_job_leaves_the_payment_pending_when_it_cannot_decide(): void
    {
        $billable = $this->billable(101);
        $payment = $this->pending($billable, minutesOld: 2000, amount: 100);
        // A paid total that differs from the prepared amount is not a decision either way.
        $this->gatewayMock()->expects($this->once())->method('lookupForExpiry')->willReturn(new GatewayPayment('PAID', 99));

        $this->runJob($billable, $payment->payment_id);

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_job_lets_configuration_and_portone_failures_propagate_and_leaves_the_payment_pending(): void
    {
        $billable = $this->billable(101);
        $payment = $this->pending($billable, minutesOld: 2000);
        $gateway = $this->gatewayMock();
        $gateway->expects($this->once())->method('lookupForExpiry')->willThrowException(new ConfigurationException('The PortOne API secret is not configured.'));

        try {
            $this->runJob($billable, $payment->payment_id);
            $this->fail('The configuration failure must propagate.');
        } catch (ConfigurationException) {
        }

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_gateway_turns_only_payment_not_found_into_a_result(): void
    {
        $gateway = new class extends PortOneGateway
        {
            public ?\Throwable $failure = null;

            public function lookup(string $paymentId): GatewayPayment
            {
                throw $this->failure;
            }
        };

        // T-003/T-021: PortOne answers an unknown payment ID with HTTP 404 PAYMENT_NOT_FOUND.
        $gateway->failure = new UnknownException('getPayment', 'Payment not found', status: 404, errorType: 'PAYMENT_NOT_FOUND');
        $result = $gateway->lookupForExpiry('never-opened');
        $this->assertFalse($result->found);
        $this->assertSame('PAYMENT_NOT_FOUND', $result->status);

        // T-003: an invalid secret is HTTP 401 UNAUTHORIZED and must still fail.
        $gateway->failure = new UnauthorizedException('Unauthorized', status: 401, errorType: 'UNAUTHORIZED');
        $this->expectException(UnauthorizedException::class);
        $gateway->lookupForExpiry('any');
    }

    public function test_prune_deletes_only_expired_payments_older_than_the_retention(): void
    {
        $billable = $this->billable(101);
        $statuses = ['EXPIRED' => [91, 89], 'PAID' => [400], 'PARTIALLY_CANCELLED' => [400], 'CANCELLED' => [400], 'PENDING' => [400]];
        $kept = [];
        $deleted = null;

        foreach ($statuses as $status => $ages) {
            foreach ($ages as $daysOld) {
                $payment = $this->pending($billable, minutesOld: 0);
                Payment::query()->whereKey($payment->getKey())->update([
                    'status' => $status,
                    'updated_at' => now()->subDays($daysOld),
                ]);
                $status === 'EXPIRED' && $daysOld === 91 ? $deleted = $payment->payment_id : $kept[] = $payment->payment_id;
            }
        }

        $this->artisan('model:prune', ['--model' => [Payment::class]])->assertExitCode(0);

        $this->assertFalse(Payment::query()->where('payment_id', $deleted)->exists());
        $this->assertSame(count($kept), Payment::query()->whereIn('payment_id', $kept)->count());
    }

    public function test_completion_recovers_an_expired_payment_paid_later(): void
    {
        $billable = $this->billable(101);
        $payment = $this->pending($billable, minutesOld: 0, amount: 100);
        $payment->forceFill(['status' => 'EXPIRED'])->save();
        // T-021: a FAILED payment ID was paid later with the same ID and then looked up as PAID 100.
        $this->gatewayMock()->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('PAID', 100));

        $this->assertSame('PAID', $billable->completePayment($payment->payment_id)->status);
    }

    public function test_refund_rejects_an_expired_payment_without_contacting_portone(): void
    {
        $billable = $this->billable(101);
        $payment = $this->pending($billable, minutesOld: 0);
        $payment->forceFill(['status' => 'EXPIRED'])->save();
        $this->gatewayMock()->expects($this->never())->method('cancel');

        $this->expectException(InvalidPaymentStatusException::class);
        $billable->refund($payment->payment_id, 'Customer request');
    }

    public function test_the_package_registers_no_schedule(): void
    {
        $this->assertSame([], $this->app->make(Schedule::class)->events());
    }

    private function runJob(ExpiryTestBillableModel $billable, string $paymentId): void
    {
        (new ExpirePendingPayment($billable->getMorphClass(), '101', $paymentId))->handle($this->app->make(PaymentOperations::class));
    }

    private function pending(ExpiryTestBillableModel $billable, int $minutesOld, int $amount = 1000): Payment
    {
        $payment = $billable->preparePayment($amount, 'Order A');
        Payment::query()->whereKey($payment->getKey())->update(['created_at' => now()->subMinutes($minutesOld)]);

        return $payment->fresh();
    }

    private function billable(int $key): ExpiryTestBillableModel
    {
        $billable = new ExpiryTestBillableModel;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }

    private function gatewayMock(): PaymentGateway&MockObject
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }
}

class ExpiryTestBillableModel extends Model
{
    use Billable;
}
