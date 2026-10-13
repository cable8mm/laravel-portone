<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 환불할 수 없는 상태의 결제를 환불하려 할 때 발생하는 예외.
 *
 * PAID 또는 PARTIALLY_CANCELLED 상태에서만 환불할 수 있다.
 */
class InvalidPaymentStatusException extends RuntimeException {}
