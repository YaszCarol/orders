<?php

namespace App\Console\Commands;

use App\EventBus\EventBusInterface;
use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateOrder extends Command
{
    protected $signature = 'orders:create
                            {--description= : Order description}
                            {--amount= : Order amount}';

    protected $description = 'Create a test order and publish OrderCreated event';

    public function handle(EventBusInterface $eventBus): int
    {
        $description = $this->option('description')
            ?? $this->ask('Order description');

        $amount = $this->option('amount')
            ?? $this->ask('Order amount (R$)');

        $order = DB::transaction(function () use ($eventBus, $description, $amount) {
            $order = Order::create([
                'description' => $description,
                'amount' => (float) $amount,
            ]);

            $eventBus->publish(new OrderCreated($order));

            return $order;
        });

        $this->info("Order created: {$order->id}");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $order->id],
                ['Description', $order->description],
                ['Amount', 'R$ ' . number_format($order->amount, 2, ',', '.')],
                ['Status', 'pending (event in outbox)'],
            ]
        );

        return self::SUCCESS;
    }
}
