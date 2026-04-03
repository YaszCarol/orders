<?php

namespace Tests\Unit\Events;

use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\Events\OrderClassified;
use App\Models\Order;
use PHPUnit\Framework\TestCase;

class OrderClassifiedTest extends TestCase
{
    private function makeClassifiedOrder(RiskLevel $riskLevel): Order
    {
        $order = new Order();
        $order->id = '01JTEST000000000000000001';
        $order->description = 'Test order';
        $order->amount = 500.00;
        $order->risk_level = $riskLevel;
        $order->status = match ($riskLevel) {
            RiskLevel::Safe => OrderStatus::Approved,
            RiskLevel::Suspicious => OrderStatus::Reviewing,
            RiskLevel::Fraud => OrderStatus::Blocked,
        };
        $order->ai_reasoning = 'Test reasoning';

        return $order;
    }

    public function test_event_type_includes_risk_level_safe(): void
    {
        $order = $this->makeClassifiedOrder(RiskLevel::Safe);
        $event = new OrderClassified($order);

        $this->assertEquals('order.classified.safe', $event->eventType());
    }

    public function test_event_type_includes_risk_level_suspicious(): void
    {
        $order = $this->makeClassifiedOrder(RiskLevel::Suspicious);
        $event = new OrderClassified($order);

        $this->assertEquals('order.classified.suspicious', $event->eventType());
    }

    public function test_event_type_includes_risk_level_fraud(): void
    {
        $order = $this->makeClassifiedOrder(RiskLevel::Fraud);
        $event = new OrderClassified($order);

        $this->assertEquals('order.classified.fraud', $event->eventType());
    }

    public function test_payload_contains_classification_data(): void
    {
        $order = $this->makeClassifiedOrder(RiskLevel::Fraud);
        $event = new OrderClassified($order);

        $payload = $event->payload();

        $this->assertEquals($order->id, $payload['order_id']);
        $this->assertEquals('fraud', $payload['risk_level']);
        $this->assertEquals('blocked', $payload['status']);
        $this->assertEquals(500.00, $payload['amount']);
        $this->assertEquals('Test order', $payload['description']);
        $this->assertEquals('Test reasoning', $payload['reasoning']);
    }

    public function test_event_id_is_unique(): void
    {
        $order = $this->makeClassifiedOrder(RiskLevel::Safe);

        $event1 = new OrderClassified($order);
        $event2 = new OrderClassified($order);

        $this->assertNotEquals($event1->eventId(), $event2->eventId());
    }
}
