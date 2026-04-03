<?php

namespace App\Ai\Agents;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class OrderClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public Order $order) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'PROMPT'
        You are a fraud detection analyst for an e-commerce platform.
        Your job is to classify incoming orders based on their description and amount.

        Classify each order into one of three risk levels:
        - "safe": Normal order, no signs of fraud
        - "suspicious": Some red flags, needs human review
        - "fraud": Clear signs of fraudulent activity

        Consider these factors:
        - Unusually high amounts
        - Bulk purchases of high-value electronics
        - Descriptions mentioning resale, bulk, or wholesale
        - Inconsistent or vague descriptions
        - Known fraud patterns (gift cards in bulk, cryptocurrency purchases, etc.)

        Always provide clear reasoning in Portuguese (pt-BR) for your classification.
        PROMPT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'risk_level' => $schema->string()->enum(['safe', 'suspicious', 'fraud'])->required(),
            'reasoning' => $schema->string()->required(),
        ];
    }

    /**
     * Classify the order.
     */
    public function classify(): array
    {
        $prompt = sprintf(
            'Classify this order: Description: "%s" | Amount: R$ %s',
            $this->order->description,
            number_format($this->order->amount, 2, ',', '.'),
        );

        $response = $this->prompt($prompt);

        return [
            'risk_level' => $response['risk_level'],
            'reasoning' => $response['reasoning'],
        ];
    }
}
