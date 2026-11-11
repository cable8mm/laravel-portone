<?php

declare(strict_types=1);

return [
    'api_secret' => env('PORTONE_API_SECRET'),
    'webhook_secret' => env('PORTONE_WEBHOOK_SECRET'),
    'base_url' => env('PORTONE_BASE_URL'),
    'webhook_path' => env('PORTONE_WEBHOOK_PATH') ?: 'portone/webhook',
    'store_id' => env('PORTONE_STORE_ID'),
    'channel_key' => env('PORTONE_CHANNEL_KEY'),
    'pending_expire_after' => (int) (env('PORTONE_PENDING_EXPIRE_AFTER') ?: 1440),
    'expired_retention_days' => (int) (env('PORTONE_EXPIRED_RETENTION_DAYS') ?: 90),
    'webhook_retention_days' => (int) (env('PORTONE_WEBHOOK_RETENTION_DAYS') ?: 90),
    'amount_mismatch_cancel_reason' => env('PORTONE_AMOUNT_MISMATCH_CANCEL_REASON') ?: '결제 금액 불일치로 자동 취소',
    'duplicate_cancel_reason' => env('PORTONE_DUPLICATE_CANCEL_REASON') ?: '중복 결제로 자동 취소',
    // Days to wait before each retry of a failed renewal, comma-separated; after the last retry fails the subscription expires.
    'subscription_retry_after' => env('PORTONE_SUBSCRIPTION_RETRY_AFTER') ?: '1,3,5',
];
