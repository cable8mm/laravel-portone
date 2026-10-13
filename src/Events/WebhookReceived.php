<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\PortonePhp\Webhook\Webhook;

final class WebhookReceived
{
    public function __construct(
        public readonly Webhook $webhook,
        public readonly string $webhookId,
    ) {}
}
