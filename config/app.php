<?php
declare(strict_types=1);

return [
    'name' => 'Social Automation',
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string) env('APP_URL', ''), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
    'key' => env('APP_KEY', ''),
    'meta' => [
        'app_id' => env('META_APP_ID', ''),
        'app_secret' => env('META_APP_SECRET', ''),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
    ],
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID', ''),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET', ''),
        'redirect_uri' => env('LINKEDIN_REDIRECT_URI', rtrim((string)env('APP_URL', ''), '/') . '/index.php'),
        'api_version' => env('LINKEDIN_API_VERSION', '202608'),
    ],
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY', ''),
        'client_secret' => env('TIKTOK_CLIENT_SECRET', ''),
        'redirect_uri' => env('TIKTOK_REDIRECT_URI', rtrim((string)env('APP_URL', ''), '/') . '/tiktok-callback.php'),
    ],
];
