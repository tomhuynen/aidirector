<?php

declare(strict_types=1);

return [
    /**
     * The token for the healthcheck.
     */
    'healthcheck' => [
        'token' => env('HEALTHCHECK_TOKEN'),
    ],

    /**
     * The path for the system which will be used to execute the commands.
     */
    'path' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin:/opt/homebrew/bin',

    /**
     * Whether the local delay is enabled.
     *
     * This will slow down the response time of the system to simulate a real-world scenario.
     */
    'local_delay_enabled' => env('LOCAL_DELAY_ENABLED', false),

    /**
     * Upstream monitor configuration.
     */
    'upstream_monitor' => [
        'enabled' => env('UPSTREAM_MONITOR_ENABLED', false),
        'remote' => env('UPSTREAM_MONITOR_REMOTE', 'blueprint'),
        'branch' => env('UPSTREAM_MONITOR_BRANCH', 'main'),
    ],
];
