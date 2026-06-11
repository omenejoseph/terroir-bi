<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Queries\InventorySpendQuery;
use App\Support\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventorySpendController extends Controller
{
    /** Warehouse-exit spend for finished products over a date range (?from=&to=). */
    public function index(Request $request, InventorySpendQuery $query): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        [$start, $end] = Period::resolve(null, is_string($from) ? $from : null, is_string($to) ? $to : null);

        return response()->json(['data' => $query->get($start, $end)]);
    }
}
