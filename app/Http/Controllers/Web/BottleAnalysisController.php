<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreBottleAnalysisRequest;
use App\Models\BottleAnalysis;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;

/**
 * Lab/enology analyses recorded against a wine item (Product Detail ·
 * Analysis tab, Figma 449:1577) — the same StoreBottleAnalysisRequest
 * Api\BottleAnalysisController uses. Reads don't get their own route;
 * InventoryController::show() sends the item's analyses alongside everything
 * else on the page.
 */
class BottleAnalysisController extends Controller
{
    public function store(StoreBottleAnalysisRequest $request, InventoryItem $item): RedirectResponse
    {
        $item->bottleAnalyses()->create($request->validated());

        return back()->with('success', __('Analysis recorded.'));
    }

    public function destroy(InventoryItem $item, BottleAnalysis $analysis): RedirectResponse
    {
        abort_unless($analysis->inventory_item_id === $item->getKey(), 404);

        $analysis->delete();

        return back()->with('success', __('Analysis removed.'));
    }
}
