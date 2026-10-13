<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\PortonePhp\Webhook\Webhook;
use Cable8mm\PortonePhp\Webhook\WebhookVerifier;

final class PortOneWebhookVerifier
{
    public function verify(
        string $rawBody,
        ?string $webhookId,
        ?string $webhookSignature,
        ?string $webhookTimestamp,
        string $secret,
    ): Webhook {
        return (new WebhookVerifier($secret))->verify(
            $rawBody,
            $webhookId,
            $webhookSignature,
            $webhookTimestamp,
        );
    }
}
