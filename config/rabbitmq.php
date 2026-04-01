<?php

return [
    'host' => env('RABBITMQ_HOST', 'localhost'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    'exchange' => env('RABBITMQ_EXCHANGE', 'orders.topic'),
    'exchange_type' => 'topic',

    'queues' => [
        'safe' => [
            'name' => 'orders.safe',
            'routing_key' => 'order.safe',
        ],
        'suspicious' => [
            'name' => 'orders.suspicious',
            'routing_key' => 'order.suspicious',
        ],
        'fraud' => [
            'name' => 'orders.fraud',
            'routing_key' => 'order.fraud',
        ],
        'audit' => [
            'name' => 'orders.audit',
            'routing_key' => ['order.suspicious', 'order.fraud'],
        ],
    ],
];
