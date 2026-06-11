<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\InventoryCheckData;
use App\Http\Controllers\Controller;
use App\Models\InventoryCheck;
use Illuminate\Http\JsonResponse;

class InventoryCheckController extends Controller
{
    /** Audit history of stocktakes, newest first (paginated). */
    public function index(): JsonResponse
    {
        $paginator = InventoryCheck::query()
            ->with('performedBy')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'data' => array_map(
                fn (InventoryCheck $check) => InventoryCheckData::fromModel($check)->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** A single check with its adjusted lines. */
    public function show(InventoryCheck $check): JsonResponse
    {
        $check->load('performedBy', 'lines');

        return response()->json(['data' => InventoryCheckData::fromModel($check, withLines: true)->toArray()]);
    }
}
