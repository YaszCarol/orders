<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;

    public function connect(): void
    {
        if ($this->connection && $this->connection->isConnected()) {
            return;
        }

        $this->connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
        );

        $this->channel = $this->connection->channel();
    }

    public function publish(string $queue, array $data): void
    {
        $this->connect();

        $this->channel->queue_declare($queue, false, true, false, false);

        $message = new AMQPMessage(json_encode($data), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);

        $this->channel->basic_publish($message, '', $queue);
    }

    public function consume(string $queue, callable $callback): void
    {
        $this->connect();

        $this->channel->queue_declare($queue, false, true, false, false);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($callback) {
                $data = json_decode($message->getBody(), true);
                $callback($data);
                $message->ack();
            }
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function consumeAsync(string $queue, callable $callback): void
    {
        $this->connect();

        $this->channel->queue_declare($queue, false, true, false, false);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($callback) {
                $data = json_decode($message->getBody(), true);
                $callback($data);
                $message->ack();
            }
        );
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
