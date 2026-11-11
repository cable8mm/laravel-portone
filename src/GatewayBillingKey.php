<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

/**
 * PortOne 빌링키 조회 결과를 담는 값 객체. 빌링키 값은 담지 않는다.
 */
final class GatewayBillingKey
{
    /**
     * @param  string  $status  PortOne 빌링키 상태(ISSUED, DELETED)
     * @param  string|null  $customerId  발급 때 보낸 고객 ID(customer.id, 응답에 없으면 null)
     * @param  string|null  $issueId  발급 때 보낸 Issue ID(issueId, 응답에 없으면 null)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $customerId,
        public readonly ?string $issueId,
    ) {}
}
