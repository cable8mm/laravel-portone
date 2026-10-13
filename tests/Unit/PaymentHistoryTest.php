<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;

class PaymentHistoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    public function test_payments_returns_an_empty_relation_when_the_model_has_no_payments(): void
    {
        $this->migratePayments();
        $billable = $this->billable(HistoryBillableModel::class, 101);

        $payments = $billable->payments();

        $this->assertInstanceOf(MorphMany::class, $payments);
        $this->assertCount(0, $payments->get());
    }

    public function test_payments_returns_only_owned_records_newest_first(): void
    {
        $this->migratePayments();
        $billable = $this->billable(HistoryBillableModel::class, 101);
        $sameKindOtherKey = $this->billable(HistoryBillableModel::class, 102);
        $sameKeyOtherKind = $this->billable(OtherHistoryBillableModel::class, 101);

        $oldest = $this->createPayment($billable, 'payment-oldest', '2026-10-13 10:00:00');
        $newest = $this->createPayment($billable, 'payment-newest', '2026-10-13 12:00:00');
        $this->createPayment($sameKindOtherKey, 'payment-other-key', '2026-10-13 14:00:00');
        $this->createPayment($sameKeyOtherKind, 'payment-other-kind', '2026-10-13 16:00:00');

        $this->assertSame(
            [$newest->payment_id, $oldest->payment_id],
            $billable->payments()->pluck('payment_id')->all(),
        );
    }

    public function test_find_payment_returns_only_a_matching_owned_payment(): void
    {
        $this->migratePayments();
        $billable = $this->billable(HistoryBillableModel::class, 101);
        $otherBillable = $this->billable(OtherHistoryBillableModel::class, 101);
        $ownedPayment = $this->createPayment($billable, 'payment-owned', '2026-10-13 10:00:00');
        $otherPayment = $this->createPayment($otherBillable, 'payment-other', '2026-10-13 11:00:00');

        $this->assertSame($ownedPayment->payment_id, $billable->findPayment('payment-owned')?->payment_id);
        $this->assertNull($billable->findPayment('unknown-payment'));
        $this->assertNull($billable->findPayment($otherPayment->payment_id));
    }

    private function migratePayments(): void
    {
        $this->artisan('migrate', [
            '--path' => dirname(__DIR__, 2).'/database/migrations/2026_10_13_000001_create_portone_payments_table.php',
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * @param  class-string<HistoryBillableModel|OtherHistoryBillableModel>  $model
     */
    private function billable(string $model, int $key): HistoryBillableModel|OtherHistoryBillableModel
    {
        $billable = new $model;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }

    private function createPayment(Model $billable, string $paymentId, string $createdAt): Payment
    {
        return Payment::query()->create([
            'payment_id' => $paymentId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'customer_id' => 'customer-1',
            'order_name' => 'Test order',
            'amount' => 10000,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($createdAt),
        ]);
    }
}

class HistoryBillableModel extends Model
{
    use Billable;
}

class OtherHistoryBillableModel extends Model
{
    use Billable;
}
