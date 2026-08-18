<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'db'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'happy_family'),
            'username' => env('DB_USERNAME', 'happy_family'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'default' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
        ],
        'cache' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 2.0),
        ],
        'queue' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 2.0),
            // Queue workers use BLPOP for REDIS_QUEUE_BLOCK_FOR seconds. This
            // socket read timeout must stay comfortably above block_for or
            // PhpRedis will raise false "read error on connection" exceptions.
            'read_timeout' => (float) env('REDIS_QUEUE_READ_TIMEOUT', 15.0),
        ],
    ],
];
