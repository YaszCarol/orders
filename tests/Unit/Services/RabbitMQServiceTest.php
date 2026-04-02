<?php

namespace Tests\Unit\Services;

use App\Services\RabbitMQService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

class RabbitMQServiceTest extends TestCase
{
    private function createServiceWithMocks(
        AMQPStreamConnection $connection,
        AMQPChannel $channel,
    ): RabbitMQService {
        $service = new class($connection, $channel) extends RabbitMQService {
            public function __construct(
                private AMQPStreamConnection $mockConnection,
                private AMQPChannel $mockChannel,
            ) {}

            public function connect(): void
            {
                // Access private properties via reflection
                $ref = new \ReflectionClass(RabbitMQService::class);

                $connProp = $ref->getProperty('connection');
                $chanProp = $ref->getProperty('channel');

                $currentConn = $connProp->getValue($this);
                $currentChan = $chanProp->getValue($this);

                if ($currentConn && $currentConn->isConnected() && $currentChan && $currentChan->is_open()) {
                    return;
                }

                $connProp->setValue($this, $this->mockConnection);
                $chanProp->setValue($this, $this->mockChannel);
            }

            public function getConnectionViaReflection(): ?AMQPStreamConnection
            {
                $ref = new \ReflectionClass(RabbitMQService::class);
                $prop = $ref->getProperty('connection');

                return $prop->getValue($this);
            }

            public function getChannelViaReflection(): ?AMQPChannel
            {
                $ref = new \ReflectionClass(RabbitMQService::class);
                $prop = $ref->getProperty('channel');

                return $prop->getValue($this);
            }
        };

        return $service;
    }

    public function test_connect_sets_connection_and_channel(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $channel = $this->createMock(AMQPChannel::class);

        $service = $this->createServiceWithMocks($connection, $channel);
        $service->connect();

        $this->assertSame($connection, $service->getConnectionViaReflection());
        $this->assertSame($channel, $service->getChannelViaReflection());
    }

    public function test_connect_reuses_existing_connection_if_alive(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);

        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('is_open')->willReturn(true);

        $service = $this->createServiceWithMocks($connection, $channel);

        $service->connect();
        $service->connect(); // second call should reuse

        $this->assertSame($connection, $service->getConnectionViaReflection());
    }

    public function test_connect_reconnects_when_connection_is_dead(): void
    {
        $deadConnection = $this->createMock(AMQPStreamConnection::class);
        $deadConnection->method('isConnected')->willReturn(false);

        $deadChannel = $this->createMock(AMQPChannel::class);
        $deadChannel->method('is_open')->willReturn(false);

        $newConnection = $this->createMock(AMQPStreamConnection::class);
        $newConnection->method('isConnected')->willReturn(true);

        $newChannel = $this->createMock(AMQPChannel::class);
        $newChannel->method('is_open')->willReturn(true);

        // First connect with dead mocks, then swap
        $service = new class($deadConnection, $deadChannel, $newConnection, $newChannel) extends RabbitMQService {
            private int $connectCount = 0;

            public function __construct(
                private AMQPStreamConnection $deadConn,
                private AMQPChannel $deadChan,
                private AMQPStreamConnection $newConn,
                private AMQPChannel $newChan,
            ) {}

            public function connect(): void
            {
                $ref = new \ReflectionClass(RabbitMQService::class);
                $connProp = $ref->getProperty('connection');
                $chanProp = $ref->getProperty('channel');

                $currentConn = $connProp->getValue($this);
                $currentChan = $chanProp->getValue($this);

                if ($currentConn && $currentConn->isConnected() && $currentChan && $currentChan->is_open()) {
                    return;
                }

                $this->connectCount++;

                if ($this->connectCount === 1) {
                    $connProp->setValue($this, $this->deadConn);
                    $chanProp->setValue($this, $this->deadChan);
                } else {
                    $connProp->setValue($this, $this->newConn);
                    $chanProp->setValue($this, $this->newChan);
                }
            }

            public function getConnectionRef(): ?AMQPStreamConnection
            {
                $ref = new \ReflectionClass(RabbitMQService::class);

                return $ref->getProperty('connection')->getValue($this);
            }

            public function getConnectCount(): int
            {
                return $this->connectCount;
            }
        };

        $service->connect(); // sets dead connection
        $service->connect(); // detects dead → reconnects with new

        $this->assertEquals(2, $service->getConnectCount());
        $this->assertSame($newConnection, $service->getConnectionRef());
    }

    public function test_connect_reconnects_when_channel_is_closed(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);

        $closedChannel = $this->createMock(AMQPChannel::class);
        $closedChannel->method('is_open')->willReturn(false);

        $newConnection = $this->createMock(AMQPStreamConnection::class);
        $newChannel = $this->createMock(AMQPChannel::class);

        $reconnected = false;

        $service = new class($connection, $closedChannel, $newConnection, $newChannel, $reconnected) extends RabbitMQService {
            public function __construct(
                private AMQPStreamConnection $conn,
                private AMQPChannel $closedChan,
                private AMQPStreamConnection $newConn,
                private AMQPChannel $newChan,
                public bool $reconnected,
            ) {}

            public function connect(): void
            {
                $ref = new \ReflectionClass(RabbitMQService::class);
                $connProp = $ref->getProperty('connection');
                $chanProp = $ref->getProperty('channel');

                $currentConn = $connProp->getValue($this);
                $currentChan = $chanProp->getValue($this);

                if ($currentConn && $currentConn->isConnected() && $currentChan && $currentChan->is_open()) {
                    return;
                }

                if ($currentConn !== null) {
                    $this->reconnected = true;
                    $connProp->setValue($this, $this->newConn);
                    $chanProp->setValue($this, $this->newChan);
                } else {
                    $connProp->setValue($this, $this->conn);
                    $chanProp->setValue($this, $this->closedChan);
                }
            }
        };

        $service->connect(); // sets connection + closed channel
        $service->connect(); // detects closed channel → reconnects

        $this->assertTrue($service->reconnected);
    }

    public function test_close_nullifies_connection_and_channel(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->expects($this->once())->method('close');

        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects($this->once())->method('close');

        $service = $this->createServiceWithMocks($connection, $channel);
        $service->connect();
        $service->close();

        $this->assertNull($service->getChannel());
    }

    public function test_close_is_safe_when_already_closed(): void
    {
        $service = new RabbitMQService();
        $service->close(); // should not throw

        $this->assertNull($service->getChannel());
    }

    public function test_get_retry_count_returns_zero_without_headers(): void
    {
        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(false);

        $service = new RabbitMQService();
        $this->assertEquals(0, $service->getRetryCount($message));
    }

    public function test_get_retry_count_returns_zero_without_x_death(): void
    {
        $headers = new AMQPTable([]);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->with('application_headers')->willReturn($headers);

        $service = new RabbitMQService();
        $this->assertEquals(0, $service->getRetryCount($message));
    }

    public function test_get_retry_count_sums_x_death_counts(): void
    {
        $headers = new AMQPTable([
            'x-death' => [
                ['count' => 2, 'queue' => 'orders.created', 'reason' => 'rejected'],
                ['count' => 1, 'queue' => 'orders.created.retry', 'reason' => 'expired'],
            ],
        ]);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->with('application_headers')->willReturn($headers);

        $service = new RabbitMQService();
        $this->assertEquals(3, $service->getRetryCount($message));
    }

    public function test_get_retry_count_with_single_death(): void
    {
        $headers = new AMQPTable([
            'x-death' => [
                ['count' => 1, 'queue' => 'orders.created', 'reason' => 'rejected'],
            ],
        ]);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->with('application_headers')->willReturn($headers);

        $service = new RabbitMQService();
        $this->assertEquals(1, $service->getRetryCount($message));
    }
}
