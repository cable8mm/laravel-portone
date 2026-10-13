<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * PortOne에서 미결제로 조회된 결제를 확정하려 할 때 발생하는 예외.
 */
class PaymentNotPaidException extends RuntimeException {}
