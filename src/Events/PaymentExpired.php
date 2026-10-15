<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 만료 확인에서 미결제가 확인되어 결제 레코드가 EXPIRED가 되었음을 알리는 이벤트.
 */
final class PaymentExpired extends PaymentExpiryEvent {}
