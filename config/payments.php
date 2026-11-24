<?php

return [
    'przelewy24' => [
        'enabled' => env('P24_ENABLED', false),
        'environment' => env('P24_ENVIRONMENT', 'sandbox'),
        'live_enabled' => env('P24_LIVE_ENABLED', false),
        'merchant_id' => env('P24_MERCHANT_ID'),
        'pos_id' => env('P24_POS_ID'),
        'api_key' => env('P24_API_KEY'),
        'crc' => env('P24_CRC'),
        'return_url' => env('P24_RETURN_URL'),
        'status_url' => env('P24_STATUS_URL'),
    ],
];
