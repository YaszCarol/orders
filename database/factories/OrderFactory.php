<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, 1, 9999),
            'status' => OrderStatus::Pending,
        ];
    }

    public function classified(RiskLevel $riskLevel = RiskLevel::Safe): static
    {
        $status = match ($riskLevel) {
            RiskLevel::Safe => OrderStatus::Approved,
            RiskLevel::Suspicious => OrderStatus::Reviewing,
            RiskLevel::Fraud => OrderStatus::Blocked,
        };

        return $this->state([
            'risk_level' => $riskLevel,
            'ai_reasoning' => fake()->sentence(),
            'status' => $status,
        ]);
    }
}
