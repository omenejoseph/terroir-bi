<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Queries\CashFlowForecastQuery;
use Illuminate\Http\JsonResponse;

class CashFlowController extends Controller
{
    public function index(CashFlowForecastQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }
}
