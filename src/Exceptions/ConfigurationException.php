<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Exceptions;

use RuntimeException;

/**
 * 필수 설정(API Secret, 웹훅 Secret 등)이 없을 때 발생하는 예외.
 *
 * 외부 요청을 보내기 전에 발생하므로 PortOne 호출도 레코드 변경도 없다.
 */
class ConfigurationException extends RuntimeException {}
