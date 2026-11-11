<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\BillingKey;

/**
 * 빌링키 기록의 상태 변경 1건을 알리는 이벤트의 공통 부분.
 *
 * 커밋된 변경 1건당 정확히 1번, 커밋 뒤에 발송된다. 기록의 billing_key는 암호화한 값이며 이벤트에 평문 빌링키는 없다.
 */
abstract class BillingKeyEvent
{
    /**
     * @param  BillingKey  $billingKey  저장 뒤의 빌링키 기록
     * @param  BillingKeyEventSource  $source  기록을 바꾼 진입점
     */
    public function __construct(
        public readonly BillingKey $billingKey,
        public readonly BillingKeyEventSource $source,
    ) {}
}
