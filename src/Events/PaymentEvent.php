<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Payment;

/**
 * 결제 레코드의 상태 변경 1건을 알리는 이벤트의 공통 부분.
 *
 * 무엇이 바꿨든(브라우저 복귀, 웹훅, 만료 확인, 동기화, 환불) 커밋된 변경 1건당 정확히 1번, 커밋 뒤에 발송된다.
 * 저장이 롤백되거나 바뀐 것이 없으면 발송되지 않는다.
 */
abstract class PaymentEvent
{
    /** PortOne 결제 식별자 */
    public readonly string $paymentId;

    /** 소유 Billable 모델의 morph 타입 */
    public readonly string $billableType;

    /** 소유 Billable 모델의 키 */
    public readonly string $billableId;

    /**
     * @param  Payment  $payment  저장 뒤의 결제 레코드
     * @param  PaymentEventSource  $source  레코드를 바꾼 진입점
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentEventSource $source,
    ) {
        $this->paymentId = $payment->payment_id;
        $this->billableType = $payment->billable_type;
        $this->billableId = $payment->billable_id;
    }
}
