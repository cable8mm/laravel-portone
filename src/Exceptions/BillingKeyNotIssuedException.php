<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * PortOne이 빌링키를 발급하지 않았다고 하거나 다른 고객에게 발급했다고 할 때 발생하는 예외.
 *
 * 빌링키 기록은 FAILED가 된다. ISSUED 기록에 다른 빌링키를 넘겼을 때도 발생하며, 이때는 PortOne에 요청하지 않고 기록을 바꾸지 않는다.
 */
class BillingKeyNotIssuedException extends RuntimeException {}
