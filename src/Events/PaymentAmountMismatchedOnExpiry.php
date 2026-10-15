<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Payment;

/**
 * 만료 확인에서 다른 금액으로 결제된 것이 확인되어 자동 취소 규칙을 적용했음을 알리는 이벤트.
 *
 * 자동 취소가 성공하면 레코드는 AMOUNT_MISMATCH_CANCELLED(wasCancelled true), 실패하면
 * AMOUNT_MISMATCH(wasCancelled false)이다. 실패한 경우 이 이벤트 다음에 잡이 PortOne 예외로 실패한다.
 */
final class PaymentAmountMismatchedOnExpiry extends PaymentExpiryEvent
{
    /**
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  Payment  $payment  저장 뒤의 결제 레코드
     * @param  string  $billableType  소유 Billable 모델의 morph 타입
     * @param  string  $billableId  소유 Billable 모델의 키
     * @param  bool  $wasCancelled  PortOne이 자동 취소를 받아들였는지
     */
    public function __construct(
        string $paymentId,
        Payment $payment,
        string $billableType,
        string $billableId,
        public readonly bool $wasCancelled,
    ) {
        parent::__construct($paymentId, $payment, $billableType, $billableId);
    }
}
