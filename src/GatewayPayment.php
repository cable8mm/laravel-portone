<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

/**
 * PortOne 조회 결과를 담는 값 객체.
 */
final class GatewayPayment
{
    /**
     * @param  string  $status  PortOne 결제 상태(예: PAID)
     * @param  int|null  $totalAmount  PortOne 실결제 총액(원, 미결제 시 null 가능)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $totalAmount,
    ) {}
}
