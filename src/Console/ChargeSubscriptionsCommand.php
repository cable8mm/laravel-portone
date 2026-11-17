<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Console;

use Cable8mm\LaravelPortone\Jobs\ChargeSubscription;
use Cable8mm\LaravelPortone\SubscriptionOperations;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * 결제할 때가 된 구독마다 결제 잡을 하나씩 큐에 넣는 명령.
 *
 * PortOne을 호출하지 않는다. 스케줄 등록은 애플리케이션이 한다.
 */
class ChargeSubscriptionsCommand extends Command
{
    protected $signature = 'portone:charge-subscriptions';

    protected $description = 'Queue a charge for every ACTIVE or PAST_DUE subscription whose next charge is due';

    public function handle(SubscriptionOperations $subscriptions, Dispatcher $bus): int
    {
        $due = $subscriptions->dueIds(now());

        foreach ($due as $subscriptionId) {
            $bus->dispatch(new ChargeSubscription($subscriptionId));
        }

        $this->info(sprintf('Queued %d subscription charge(s).', count($due)));

        return self::SUCCESS;
    }
}
