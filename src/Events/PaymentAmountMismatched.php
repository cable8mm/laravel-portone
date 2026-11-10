<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Payment;

/**
 * 결제 레코드가 AMOUNT_MISMATCH_CANCELLED(wasCancelled true) 또는 AMOUNT_MISMATCH(wasCancelled false)가 되었다.
 */
final class PaymentAmountMismatched extends PaymentEvent
{
    /**
     * @param  Payment  $payment  저장 뒤의 결제 레코드
     * @param  PaymentEventSource  $source  레코드를 바꾼 진입점
     * @param  bool  $wasCancelled  PortOne에서 결제가 취소되었는지
     */
    public function __construct(
        Payment $payment,
        PaymentEventSource $source,
        public readonly bool $wasCancelled,
    ) {
        parent::__construct($payment, $source);
    }
}
