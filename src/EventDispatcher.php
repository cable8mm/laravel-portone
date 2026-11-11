<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Illuminate\Support\Facades\DB;

/**
 * 작업 단위 안에서 만들어진 결제 이벤트를 붙잡아 두었다가 가장 바깥 트랜잭션이 커밋된 뒤 1번 발송한다.
 *
 * 트랜잭션이 롤백되면 그 안에서 붙잡은 이벤트는 버린다. 트랜잭션 밖이면 바로 발송한다.
 */
class EventDispatcher
{
    /**
     * 커밋 뒤에 이벤트를 발송하도록 예약한다.
     *
     * @param  object  $event  발송할 이벤트
     */
    public function dispatchAfterCommit(object $event): void
    {
        DB::afterCommit(static function () use ($event): void {
            event($event);
        });
    }
}
