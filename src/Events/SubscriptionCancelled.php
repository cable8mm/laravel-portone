<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

/**
 * 구독이 현재 기간이 끝날 때 끝나도록 해지 예약되었다(cancel_at_period_end).
 */
final class SubscriptionCancelled extends SubscriptionEvent {}
