<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQService
{
    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;

    public function connect(): void
    {
        if ($this->connection && $this->connection->isConnected() && $this->channel && $this->channel->is_open()) {
            return;
        }

        $this->channel = null;
        $this->connection = null;

        $this->connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
        );

        $this->channel = $this->connection->channel();
    }

    public function setupTopology(): void
    {
        $this->connect();

        $exchange = config('rabbitmq.exchange');
        $exchangeType = config('rabbitmq.exchange_type');
        $retryExchange = config('rabbitmq.retry.exchange');
        $retryDelayMs = config('rabbitmq.retry.delay_ms');

        // Main exchange
        $this->channel->exchange_declare($exchange, $exchangeType, false, true, false);

        // Retry exchange (direct)
        $this->channel->exchange_declare($retryExchange, 'direct', false, true, false);

        foreach (config('rabbitmq.queues') as $queue) {
            $queueName = $queue['name'];
            $routingKeys = (array) $queue['routing_key'];
            $hasRetry = $queue['retry'] ?? false;

            if ($hasRetry) {
                // Main queue with DLX pointing to retry exchange
                $this->channel->queue_declare($queueName, false, true, false, false, false, new AMQPTable([
                    'x-dead-letter-exchange' => $retryExchange,
                    'x-dead-letter-routing-key' => "{$queueName}.retry",
                ]));

                // Retry queue with TTL, DLX pointing back to main exchange
                $this->channel->queue_declare("{$queueName}.retry", false, true, false, false, false, new AMQPTable([
                    'x-message-ttl' => $retryDelayMs,
                    'x-dead-letter-exchange' => $exchange,
                ]));

                // Bind retry queue to retry exchange
                $this->channel->queue_bind("{$queueName}.retry", $retryExchange, "{$queueName}.retry");
            } else {
                $this->channel->queue_declare($queueName, false, true, false, false);
            }

            // Bind main queue to main exchange
            foreach ($routingKeys as $key) {
                $this->channel->queue_bind($queueName, $exchange, $key);
            }
        }
    }

    public function publish(string $routingKey, array $data): void
    {
        $this->connect();

        $exchange = config('rabbitmq.exchange');

        $this->channel->exchange_declare($exchange, config('rabbitmq.exchange_type'), false, true, false);

        $message = new AMQPMessage(json_encode($data), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);

        $this->channel->basic_publish($message, $exchange, $routingKey);
    }

    public function consumeAsync(string $queue, callable $callback): void
    {
        $this->connect();

        $this->channel->basic_qos(0, 1, false);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            $callback,
        );
    }

    public function getRetryCount(AMQPMessage $message): int
    {
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')
            : null;

        if (! $headers) {
            return 0;
        }

        $deaths = $headers->getNativeData()['x-death'] ?? [];

        $count = 0;
        foreach ($deaths as $death) {
            $count += $death['count'] ?? 0;
        }

        return $count;
    }

    public function getChannel(): ?\PhpAmqpLib\Channel\AMQPChannel
    {
        return $this->channel;
    }

    public function close(): void
    {
        if ($this->channel) {
            $this->channel->close();
            $this->channel = null;
        }

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
