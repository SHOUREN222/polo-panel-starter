<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InstanceController extends Controller
{
    public function index(Request $request)
    {
        $instances = $request->user()->subscriptionInstances()->with('plan:id,name')->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $instances->map(fn ($instance) => [
                'id' => $instance->id,
                'plan_name' => $instance->plan->name,
                'status' => $instance->status,
                'transfer_enable' => $instance->transfer_enable,
                'u' => $instance->u,
                'd' => $instance->d,
                'expired_at' => $instance->expired_at?->toIso8601String(),
                'meta' => $instance->meta,
            ]),
        ]);
    }
}
