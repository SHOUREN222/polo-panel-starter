<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::where('sell', true)->orderBy('sort')->get([
            'id', 'name', 'type', 'transfer_enable', 'speed_limit',
            'device_limit', 'prices',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $plans,
        ]);
    }
}
