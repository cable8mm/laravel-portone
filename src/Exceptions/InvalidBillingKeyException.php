<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * ISSUED가 아니거나 호출한 Billable 모델의 것이 아닌 빌링키 기록을 삭제·결제하려 할 때 발생하는 예외.
 *
 * PortOne에 요청하지 않고 기록을 바꾸지 않는다.
 */
class InvalidBillingKeyException extends RuntimeException {}
