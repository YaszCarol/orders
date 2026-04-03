<?php

namespace App\Events;

use App\Models\Order;
use DateTimeImmutable;
use Illuminate\Support\Str;

class OrderCreated implements DomainEvent
{
    private string $eventId;
    private DateTimeImmutable $occurredAt;

    public function __construct(public readonly Order $order)
    {
        $this->eventId = Str::ulid()->toString();
        $this->occurredAt = new DateTimeImmutable();
    }

    public function aggregateId(): string
    {
        return $this->order->id;
    }

    public function aggregateType(): string
    {
        return 'order';
    }

    public function eventType(): string
    {
        return 'order.created';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->order->id,
            'description' => $this->order->description,
            'amount' => $this->order->amount,
        ];
    }
}
