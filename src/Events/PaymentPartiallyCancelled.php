<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 부분 취소 웹훅(Transaction.PartialCancelled)을 알리는 이벤트.
 */
final class PaymentPartiallyCancelled extends PaymentWebhookEvent {}
