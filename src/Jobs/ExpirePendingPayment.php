<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Jobs;

use Cable8mm\LaravelPortone\PaymentOperations;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * 오래된 PENDING 결제 1건을 PortOne에 확인하는 큐 잡.
 *
 * portone:expire-pending이 레코드마다 하나씩 큐에 넣고, 애플리케이션의 큐 워커가 실행한다.
 * 설정 누락·PortOne 실패는 감싸지 않고 전파되어 큐의 실패 잡으로 남는다.
 */
class ExpirePendingPayment implements ShouldQueue
{
    /**
     * @param  string  $billableType  소유 Billable 모델의 morph 타입
     * @param  string  $billableId  소유 Billable 모델의 키
     * @param  string  $paymentId  PortOne 결제 식별자
     */
    public function __construct(
        public readonly string $billableType,
        public readonly string $billableId,
        public readonly string $paymentId,
    ) {}

    /**
     * 결제 1건의 만료 확인을 실행한다.
     */
    public function handle(PaymentOperations $operations): void
    {
        $operations->expire($this->billableType, $this->billableId, $this->paymentId);
    }
}
