<?php

return [
    // Allow anyone to sign up after the first account exists.
    'open_registration' => (bool) env('DROIDS_OPEN_REGISTRATION', false),

    // Time zone of the subscription schedule grid (the hours you paint in Subscriptions).
    'timezone' => env('DROIDS_TIMEZONE', 'UTC'),

    // A running droid that has not reported for this many minutes is marked failed.
    'run_timeout' => (int) env('DROIDS_RUN_TIMEOUT', 15),

    // Settings for `php artisan droids:work`, read on the machine that runs the droids.
    'worker' => [
        'server' => env('DROIDS_SERVER'),
        'token' => env('DROIDS_WORKER_TOKEN'),
        // Each run gets a fresh clone here, removed when it finishes.
        'workspace' => env('DROIDS_WORKSPACE', ($_SERVER['HOME'] ?? sys_get_temp_dir()).'/.droids/work'),
        // One CLI login folder per subscription: <logins>/<subscription slug>.
        'logins' => env('DROIDS_LOGINS', ($_SERVER['HOME'] ?? sys_get_temp_dir()).'/.droids/logins'),
        // The subscription your everyday `claude` / `codex` login belongs to, if any.
        'default_login' => [
            'claude' => env('DROIDS_DEFAULT_CLAUDE'),
            'codex' => env('DROIDS_DEFAULT_CODEX'),
        ],
        'bin' => [
            'claude' => env('DROIDS_CLAUDE_BIN', 'claude'),
            'codex' => env('DROIDS_CODEX_BIN', 'codex'),
        ],
        'git_host' => env('DROIDS_GIT_HOST', 'https://github.com'),
        'timeout' => (int) env('DROIDS_RUN_MINUTES', 60),
    ],
];
