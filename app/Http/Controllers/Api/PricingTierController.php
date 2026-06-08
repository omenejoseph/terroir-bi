<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Pricing\CreatePricingTierAction;
use App\Actions\Pricing\UpdatePricingTierAction;
use App\DataTransferObjects\PricingTierData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePricingTierRequest;
use App\Http\Requests\Pricing\UpdatePricingTierRequest;
use App\Models\PricingTier;
use Illuminate\Http\JsonResponse;

class PricingTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = PricingTier::query()
            ->withCount('customers')
            ->orderBy('name')
            ->get()
            ->map(fn (PricingTier $tier) => PricingTierData::fromModel($tier)->toArray())
            ->values();

        return response()->json(['data' => $tiers]);
    }

    public function store(StorePricingTierRequest $request, CreatePricingTierAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->validated())->toArray()], 201);
    }

    public function update(UpdatePricingTierRequest $request, PricingTier $pricingTier, UpdatePricingTierAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($pricingTier, $request->validated())->toArray()]);
    }

    public function destroy(PricingTier $pricingTier): JsonResponse
    {
        $pricingTier->delete();

        return response()->json(status: 204);
    }
}
