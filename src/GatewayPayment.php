<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

/**
 * PortOne 조회 결과를 담는 값 객체.
 */
final class GatewayPayment
{
    /**
     * @param  string  $status  PortOne 결제 상태(예: PAID). PortOne이 결제를 모르면 PAYMENT_NOT_FOUND
     * @param  int|null  $totalAmount  PortOne 실결제 총액(원, 미결제 시 null 가능)
     * @param  bool  $found  PortOne이 결제를 알고 있는지(만료 확인용 조회에서만 false가 될 수 있다)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $totalAmount,
        public readonly bool $found = true,
    ) {}
}
