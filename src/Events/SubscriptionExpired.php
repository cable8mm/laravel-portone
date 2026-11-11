<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 마지막 재시도까지 갱신 결제가 실패해 구독이 EXPIRED가 되었다.
 */
final class SubscriptionExpired extends SubscriptionEvent {}
