<?php

return [
    'host' => env('RABBITMQ_HOST', 'localhost'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    'exchange' => env('RABBITMQ_EXCHANGE', 'orders.events'),
    'exchange_type' => 'topic',

    'retry' => [
        'exchange' => 'orders.retry',
        'max_attempts' => 3,
        'delay_ms' => 5000,
    ],

    'queues' => [
        'created' => [
            'name' => 'orders.created',
            'routing_key' => 'order.created',
            'retry' => true,
        ],
        'classified_safe' => [
            'name' => 'orders.classified.safe',
            'routing_key' => 'order.classified.safe',
        ],
        'classified_suspicious' => [
            'name' => 'orders.classified.suspicious',
            'routing_key' => 'order.classified.suspicious',
        ],
        'classified_fraud' => [
            'name' => 'orders.classified.fraud',
            'routing_key' => 'order.classified.fraud',
        ],
        'audit' => [
            'name' => 'orders.audit',
            'routing_key' => [
                'order.classified.fraud',
                'order.classified.suspicious',
            ]
        ],
        'dead_letter' => [
            'name' => 'orders.dead-letter',
            'routing_key' => 'order.dead',
        ],
    ],
];
