<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 전액 취소 웹훅(Transaction.Cancelled)을 알리는 이벤트.
 */
final class PaymentCancelled extends PaymentWebhookEvent {}
