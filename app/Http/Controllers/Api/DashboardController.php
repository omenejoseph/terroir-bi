<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request, DashboardSummary $summary): JsonResponse
    {
        $range = $request->query('range');

        return response()->json([
            'data' => $summary->build(is_string($range) ? $range : null),
        ]);
    }
}
