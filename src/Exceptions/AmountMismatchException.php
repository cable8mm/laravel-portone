<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 서버 준비 금액과 PortOne 실결제액이 다를 때 발생하는 예외.
 *
 * 결제 확정이 중단되며 결제 레코드는 변경되지 않는다.
 */
class AmountMismatchException extends RuntimeException {}
