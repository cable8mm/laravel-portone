<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 결제 완료 웹훅(Transaction.Paid)을 알리는 이벤트.
 */
final class PaymentPaid extends PaymentWebhookEvent {}
