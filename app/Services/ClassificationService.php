<?php

namespace App\Services;

use App\Ai\Agents\OrderClassifier;
use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\EventBus\EventBusInterface;
use App\Events\OrderClassified;
use App\Models\Order;
use App\Repositories\OrderRepositoryInterface;
use Illuminate\Support\Facades\DB;

class ClassificationService
{
    public function __construct(
        private OrderRepositoryInterface $repository,
        private EventBusInterface $eventBus,
    ) {}

    public function classify(Order $order): void
    {
        $classifier = app(OrderClassifier::class, ['order' => $order]);
        $result = $classifier->classify();

        $riskLevel = RiskLevel::from($result['risk_level']);

        $status = match ($riskLevel) {
            RiskLevel::Safe => OrderStatus::Approved,
            RiskLevel::Suspicious => OrderStatus::Reviewing,
            RiskLevel::Fraud => OrderStatus::Blocked,
        };

        DB::transaction(function () use ($order, $riskLevel, $result, $status) {
            $this->repository->updateClassification($order, [
                'risk_level' => $riskLevel,
                'ai_reasoning' => $result['reasoning'],
                'status' => $status,
            ]);

            $this->eventBus->publish(new OrderClassified($order));
        });
    }

    public function isAlreadyClassified(Order $order): bool
    {
        return $order->risk_level !== null;
    }
}
