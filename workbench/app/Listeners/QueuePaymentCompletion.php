<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Workbench\App\Jobs\CompletePaymentFromWebhook;

/**
 * The Package changes no record while handling a webhook (FR-039); the
 * Application decides what to do. Here a Transaction.Paid webhook queues a
 * completion so a paid payment never stays PENDING or EXPIRED.
 */
class QueuePaymentCompletion
{
    public function handle(WebhookReceived $event): void
    {
        if ($event->webhook instanceof WebhookTransaction && $event->webhook->type === 'Transaction.Paid') {
            dispatch(new CompletePaymentFromWebhook($event->webhook->data->paymentId));
        }
    }
}
