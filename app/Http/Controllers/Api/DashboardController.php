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
        $period = $request->query('period');
        $range = $request->query('range');
        $from = $request->query('from');
        $to = $request->query('to');

        return response()->json([
            'data' => $summary->build(
                is_string($period) ? $period : null,
                is_string($range) ? $range : null,
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            ),
        ]);
    }
}
