<?php

namespace App\Events;

use App\Models\Order;
use DateTimeImmutable;
use Illuminate\Support\Str;

class OrderClassified implements DomainEvent
{
    private string $eventId;
    private DateTimeImmutable $occurredAt;

    public function __construct(public readonly Order $order)
    {
        $this->eventId = Str::ulid()->toString();
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventType(): string
    {
        return "order.classified.{$this->order->risk_level->value}";
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
            'risk_level' => $this->order->risk_level->value,
            'status' => $this->order->status->value,
            'amount' => $this->order->amount,
            'description' => $this->order->description,
            'reasoning' => $this->order->ai_reasoning,
        ];
    }
}
