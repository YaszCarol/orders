<?php

return [
    'host' => env('RABBITMQ_HOST', 'localhost'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    'queues' => [
        'safe' => 'orders.safe',
        'suspicious' => 'orders.suspicious',
        'fraud' => 'orders.fraud',
    ],
];
