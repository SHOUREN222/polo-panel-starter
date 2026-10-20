<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(Request $request)
    {
        $orders = $request->user()->orders()->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $orders,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // Plan is a platform-shared model pinned to the central connection.
            // A bare `exists:plans,id` would query the active tenant database.
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
            'period' => ['required', 'string'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        try {
            $order = $this->orderService->createOrder($request->user(), $plan, $data['period']);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'fail',
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ], 201);
    }

    /**
     * Mock payment gateway callback: instantly marks an order paid, for local
     * development only. HARD-GATED to non-production — in production this would
     * let any logged-in customer settle their own order for free, so it must
     * never be reachable there. Real payments go through the signed gateway
     * callbacks in GatewayPaymentController::notify().
     */
    public function pay(Request $request, string $tradeNo)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->status === Order::STATUS_CANCELLED) {
            return response()->json(['status' => 'fail', 'message' => '订单已取消'], 422);
        }

        $order = $this->orderService->markPaid($order);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }
}
