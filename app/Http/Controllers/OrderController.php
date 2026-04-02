<?php

namespace App\Http\Controllers;

use App\EventBus\EventBusInterface;
use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Order::latest()->paginate(15)
        );
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json($order);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:1000',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
        ]);

        $order = DB::transaction(function () use ($validated) {
            $order = Order::create($validated);
            $this->eventBus->publish(new OrderCreated($order));

            return $order;
        });

        return response()->json($order, 201);
    }
}
