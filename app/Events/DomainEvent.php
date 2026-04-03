<?php

namespace App\Events;

use DateTimeImmutable;

interface DomainEvent
{
    public function aggregateId(): string;

    public function aggregateType(): string;

    public function eventType(): string;

    public function occurredAt(): DateTimeImmutable;

    public function eventId(): string;

    public function payload(): array;
}
