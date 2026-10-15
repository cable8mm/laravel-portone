<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 결제 실패 웹훅(Transaction.Failed)을 알리는 이벤트.
 */
final class PaymentFailed extends PaymentWebhookEvent {}
