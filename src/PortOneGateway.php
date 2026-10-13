<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\PortonePhp\Client\PortOneClient;

class PortOneGateway implements PaymentGateway
{
    public function lookup(string $paymentId): GatewayPayment
    {
        $apiSecret = config('portone.api_secret');

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }

        $baseUrl = config('portone.base_url');
        $baseUrl = is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.portone.io';
        $client = new PortOneClient($apiSecret, new PortOneRequestExecutor($apiSecret, $baseUrl));
        $payment = $client->payment->getPayment($paymentId);
        $amount = $payment->data['amount'] ?? null;
        $totalAmount = is_array($amount) && is_int($amount['total'] ?? null)
            ? $amount['total']
            : null;

        return new GatewayPayment($payment->variant, $totalAmount);
    }

    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        $apiSecret = config('portone.api_secret');

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }

        $baseUrl = config('portone.base_url');
        $baseUrl = is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.portone.io';
        $client = new PortOneClient($apiSecret, new PortOneRequestExecutor($apiSecret, $baseUrl));
        $client->payment->cancelPayment(
            paymentId: $paymentId,
            amount: $amount,
            reason: $reason,
        );
    }
}
