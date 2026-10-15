<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Payment;

/**
 * 만료 확인 잡(ExpirePendingPayment)의 결과를 알리는 이벤트의 공통 부분.
 *
 * 레코드 저장이 커밋된 뒤에 발송된다. 저장한 것이 없으면(판단 불가, 이미 PENDING이 아님) 발송하지 않는다.
 */
abstract class PaymentExpiryEvent
{
    /**
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  Payment  $payment  저장 뒤의 결제 레코드
     * @param  string  $billableType  소유 Billable 모델의 morph 타입
     * @param  string  $billableId  소유 Billable 모델의 키
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly Payment $payment,
        public readonly string $billableType,
        public readonly string $billableId,
    ) {}
}
