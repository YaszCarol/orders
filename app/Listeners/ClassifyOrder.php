<?php

namespace App\Listeners;

use App\Ai\Agents\OrderClassifier;
use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\Events\OrderCreated;
use App\Services\RabbitMQService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ClassifyOrder implements ShouldQueue
{
    public function __construct(private RabbitMQService $rabbitMQ) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        try {
            $classifier = new OrderClassifier($order);
            $result = $classifier->classify();

            $riskLevel = RiskLevel::from($result['risk_level']);

            $status = match ($riskLevel) {
                RiskLevel::Safe => OrderStatus::Approved,
                RiskLevel::Suspicious => OrderStatus::Reviewing,
                RiskLevel::Fraud => OrderStatus::Blocked,
            };

            $order->update([
                'risk_level' => $riskLevel,
                'ai_reasoning' => $result['reasoning'],
                'status' => $status,
            ]);

            $routingKey = "order.{$riskLevel->value}";

            $this->rabbitMQ->publish($routingKey, [
                'order_id' => $order->id,
                'risk_level' => $riskLevel->value,
                'status' => $status->value,
                'amount' => $order->amount,
                'description' => $order->description,
                'reasoning' => $result['reasoning'],
            ]);

            Log::info("Order {$order->id} classified as {$riskLevel->value}, routed with key {$routingKey}");
        } catch (\Throwable $e) {
            Log::error("Failed to classify order {$order->id}: {$e->getMessage()}");

            $order->update([
                'risk_level' => RiskLevel::Suspicious,
                'ai_reasoning' => 'Classificação automática falhou: ' . $e->getMessage(),
                'status' => OrderStatus::Reviewing,
            ]);

            $this->rabbitMQ->publish('order.suspicious', [
                'order_id' => $order->id,
                'risk_level' => RiskLevel::Suspicious->value,
                'status' => OrderStatus::Reviewing->value,
                'amount' => $order->amount,
                'description' => $order->description,
                'reasoning' => 'Classificação automática falhou: ' . $e->getMessage(),
            ]);
        }
    }
}
