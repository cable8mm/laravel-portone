<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 환불 금액이 0 이하이거나 환불 가능한 잔액을 초과할 때 발생하는 예외.
 */
class InvalidRefundAmountException extends RuntimeException {}
