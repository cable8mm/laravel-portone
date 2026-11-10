<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 결제 레코드가 EXPIRED가 되었다.
 */
final class PaymentExpired extends PaymentEvent {}
