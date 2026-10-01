<?php
return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite', 'url' => env('DB_URL'), 'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
            'journal_mode' => 'WAL', 'synchronous' => 'NORMAL',
        ],
        'pgsql' => [
            'driver' => 'pgsql', 'url' => env('DB_URL'), 'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'), 'database' => env('DB_DATABASE', 'personal_os'),
            'username' => env('DB_USERNAME', 'postgres'), 'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true,
            'search_path' => 'public', 'timezone' => 'UTC', 'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
