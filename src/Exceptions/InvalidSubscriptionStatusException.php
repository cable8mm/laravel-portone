<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 구독 기록의 상태가 해지 예약·해지 취소·빌링키 변경을 받아들이지 않을 때 발생하는 예외.
 *
 * 기록을 바꾸지 않는다.
 */
class InvalidSubscriptionStatusException extends RuntimeException {}
