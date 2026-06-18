<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Queries\CellarAnalyticsQuery;
use App\Queries\CellarCostsQuery;
use Illuminate\Http\JsonResponse;

/** Read-only cellar reports (costs roll-up + analytics) under the /cellar prefix. */
class CellarController extends Controller
{
    public function costs(CellarCostsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function analytics(CellarAnalyticsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }
}
