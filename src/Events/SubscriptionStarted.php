<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 첫 기간이 결제되어 구독 기록이 ACTIVE로 만들어졌다.
 */
final class SubscriptionStarted extends SubscriptionEvent {}
