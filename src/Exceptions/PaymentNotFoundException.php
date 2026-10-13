<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 호출한 Billable 범위에 해당 결제가 없을 때 발생하는 예외.
 *
 * 다른 모델의 결제 식별자를 넘기면 이 예외가 발생한다.
 */
class PaymentNotFoundException extends RuntimeException {}
