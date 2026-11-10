<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Payment;

/**
 * PAID·PARTIALLY_CANCELLED 결제가 CANCELLED가 되었다.
 */
final class PaymentRefunded extends PaymentEvent
{
    /**
     * @param  Payment  $payment  저장 뒤의 결제 레코드
     * @param  PaymentEventSource  $source  레코드를 바꾼 진입점
     * @param  int  $refundedAmount  이번 변경으로 늘어난 cancelled_amount(원)
     */
    public function __construct(
        Payment $payment,
        PaymentEventSource $source,
        public readonly int $refundedAmount,
    ) {
        parent::__construct($payment, $source);
    }
}
