<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 빌링키 기록을 바꾼 진입점. 빌링키 이벤트(BillingKeyEvent)의 source이다.
 */
enum BillingKeyEventSource: string
{
    /** completeBillingKey(), deleteBillingKey() */
    case Call = 'call';

    /** BillingKey.Issued·BillingKey.Deleted 웹훅 */
    case Webhook = 'webhook';
}
