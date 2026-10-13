<?php

declare(strict_types=1);

return [
    'api_secret' => env('PORTONE_API_SECRET'),
    'webhook_secret' => env('PORTONE_WEBHOOK_SECRET'),
    'base_url' => env('PORTONE_BASE_URL'),
    'webhook_path' => env('PORTONE_WEBHOOK_PATH', 'portone/webhook'),
    'store_id' => env('PORTONE_STORE_ID'),
    'channel_key' => env('PORTONE_CHANNEL_KEY'),
];
