<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 빌링키 발급 확정(completeBillingKey())에 넘긴 Issue ID가 호출한 Billable 범위의 PENDING·FAILED·ISSUED 기록이 아닐 때 발생하는 예외.
 *
 * PortOne에 요청하지 않는다.
 */
class BillingKeyNotFoundException extends RuntimeException {}
