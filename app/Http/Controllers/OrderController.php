<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Repositories\OrderRepositoryInterface;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private OrderRepositoryInterface $repository,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->repository->paginate());
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

        $order = $this->orderService->create($validated);

        return response()->json($order, 201);
    }
}
