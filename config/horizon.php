<?php

use Laravel\Horizon\Horizon;

return [
    'domain' => env('HORIZON_DOMAIN', null),

    'path' => 'horizon',

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'lumina_horizon'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'environments' => [
        'production' => [
            'supervisor-acuity' => [
                'connection' => 'redis',
                'queue' => ['high', 'acuity', 'default'],
                'balance' => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 16,
                'tries' => 1,
                'timeout' => 300,
            ],
        ],

        'staging' => [
            'supervisor-acuity' => [
                'connection' => 'redis',
                'queue' => ['high', 'acuity', 'default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'tries' => 1,
                'timeout' => 300,
            ],
        ],

        'local' => [
            'supervisor-acuity' => [
                'connection' => 'redis',
                'queue' => ['high', 'acuity', 'default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries' => 1,
                'timeout' => 300,
            ],
        ],
    ],
];
