<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Queries\CashFlowAnalysisQuery;
use App\Queries\CashFlowForecastQuery;
use App\Support\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function index(CashFlowForecastQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    /** Cash Flow Analysis for a date range — KPIs, series, category split, discipline, top balances. */
    public function analysis(Request $request, CashFlowAnalysisQuery $query): JsonResponse
    {
        [$from, $to] = Period::resolve(
            $request->query('period') !== null ? (string) $request->query('period') : null,
            $request->query('from') !== null ? (string) $request->query('from') : null,
            $request->query('to') !== null ? (string) $request->query('to') : null,
        );

        return response()->json(['data' => $query->get($from, $to)]);
    }
}
