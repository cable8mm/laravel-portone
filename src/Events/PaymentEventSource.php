<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 결제 레코드를 바꾼 진입점. 결제 이벤트(PaymentEvent)의 source이다.
 */
enum PaymentEventSource: string
{
    /** completePayment(), completeByPaymentId(), 발행한 체크아웃 액션 */
    case Complete = 'complete';

    /** Transaction.Paid 웹훅의 결제 확정, 취소 웹훅의 취소 동기화 */
    case Webhook = 'webhook';

    /** 만료 확인 잡(ExpirePendingPayment) */
    case Expiry = 'expiry';

    /** syncByPaymentId() */
    case Sync = 'sync';

    /** refundPayment() */
    case Refund = 'refund';

    /** 빌링키 결제 */
    case Charge = 'charge';
}
