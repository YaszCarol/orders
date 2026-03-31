<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
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

        $order = Order::create($validated);

        return response()->json($order, 201);
    }
}
