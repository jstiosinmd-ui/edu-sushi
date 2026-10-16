<?php

return [
    'enabled' => env('SUSHI_BACKUPS_ENABLED', false),
    'time' => env('SUSHI_BACKUP_TIME', '03:00'),
    'pg_dump' => env('SUSHI_PG_DUMP', 'pg_dump'),
    'pg_restore' => env('SUSHI_PG_RESTORE', 'pg_restore'),
    'mirror_disk' => env('SUSHI_BACKUP_MIRROR_DISK'),
    'weekly_rehearsal' => env('SUSHI_BACKUP_WEEKLY_REHEARSAL', false),
    // A dedicated PostgreSQL test server is required for unattended rehearsals.
    'restore' => [
        'host' => env('SUSHI_RESTORE_HOST'), 'port' => env('SUSHI_RESTORE_PORT', 5432),
        'username' => env('SUSHI_RESTORE_USERNAME'), 'password' => env('SUSHI_RESTORE_PASSWORD'),
        'sslmode' => env('SUSHI_RESTORE_SSLMODE', 'require'),
    ],
];
