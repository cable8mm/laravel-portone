<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Http\Controllers;

use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\PortOneWebhookVerifier;
use Cable8mm\PortonePhp\Exception\WebhookVerificationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WebhookController
{
    public function __invoke(Request $request, PortOneWebhookVerifier $verifier): Response
    {
        $secret = config('portone.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response('', 500);
        }

        $webhookId = $request->headers->get('webhook-id');

        try {
            $webhook = $verifier->verify(
                $request->getContent(),
                $webhookId,
                $request->headers->get('webhook-signature'),
                $request->headers->get('webhook-timestamp'),
                $secret,
            );
        } catch (WebhookVerificationException) {
            return response('', 400);
        }

        if ($webhookId === null) {
            return response('', 400);
        }

        event(new WebhookReceived($webhook, $webhookId));

        return response('', 200);
    }
}
