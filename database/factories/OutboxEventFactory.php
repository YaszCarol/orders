<?php

namespace Database\Factories;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    public function definition(): array
    {
        $orderId = Str::ulid()->toString();

        return [
            'aggregate_id' => $orderId,
            'aggregate_type' => 'order',
            'event_type' => 'order.created',
            'routing_key' => 'order.created',
            'payload' => [
                'event_id' => Str::ulid()->toString(),
                'event_type' => 'order.created',
                'occurred_at' => now()->toIso8601String(),
                'payload' => [
                    'order_id' => $orderId,
                    'description' => fake()->sentence(),
                    'amount' => fake()->randomFloat(2, 1, 9999),
                ],
            ],
        ];
    }

    public function published(): static
    {
        return $this->state([
            'published_at' => now(),
        ]);
    }
}
