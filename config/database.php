<?php
declare(strict_types=1);

return [
    'host' => env('DB_HOST', 'localhost'),
    'port' => (int) env('DB_PORT', '3306'),
    'name' => env('DB_NAME', ''),
    'user' => env('DB_USER', ''),
    'pass' => env('DB_PASS', ''),
];

