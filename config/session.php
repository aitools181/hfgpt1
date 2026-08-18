<?php

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => filter_var(env('SESSION_ENCRYPT', false), FILTER_VALIDATE_BOOL),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION', 'pgsql'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'happy_family_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => filter_var(env('SESSION_SECURE_COOKIE', true), FILTER_VALIDATE_BOOL),
    'http_only' => true,
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
];
