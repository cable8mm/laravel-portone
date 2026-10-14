<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Console;

use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\PaymentLedger;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * 오래된 PENDING 결제마다 만료 확인 잡을 하나씩 큐에 넣는 명령.
 *
 * PortOne을 호출하지 않는다. 스케줄 등록은 애플리케이션이 한다.
 */
class ExpirePendingPaymentsCommand extends Command
{
    protected $signature = 'portone:expire-pending';

    protected $description = 'Queue a PortOne check for every PENDING payment older than portone.pending_expire_after minutes';

    public function handle(PaymentLedger $payments, Dispatcher $bus): int
    {
        $minutes = config('portone.pending_expire_after');
        $createdBefore = now()->subMinutes(is_numeric($minutes) ? (int) $minutes : 1440);
        $stale = $payments->stalePending($createdBefore);

        foreach ($stale as $payment) {
            $bus->dispatch(new ExpirePendingPayment($payment->billable_type, $payment->billable_id, $payment->payment_id));
        }

        $this->info(sprintf('Queued %d PortOne expiry check(s).', $stale->count()));

        return self::SUCCESS;
    }
}
