<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Pricing\CreatePricingTierAction;
use App\Actions\Pricing\UpdatePricingTierAction;
use App\DataTransferObjects\PricingTierData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePricingTierRequest;
use App\Http\Requests\Pricing\UpdatePricingTierRequest;
use App\Models\PricingTier;
use App\Services\Pricing\TierPricePreview;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers · Pricing tiers: the named price books (with a default rebate) that customers are put
 * on. Inertia counterpart of Api\PricingTierController, sharing its actions and requests. Reading
 * needs `customers.view`; every write `customers.manage`, the same gates as the customers themselves.
 * Per-item tier prices are set from the product (Inventory), not here.
 */
class PricingTierController extends Controller
{
    public function index(TierPricePreview $preview): Response
    {
        $models = PricingTier::query()
            ->withCount('customers')
            ->withCount('tierPrices')
            ->orderBy('name')
            ->get();

        $tiers = $models
            ->map(fn (PricingTier $tier): array => PricingTierData::fromModel($tier)->toArray() + [
                'prices_count' => (int) $tier->getAttribute('tier_prices_count'),
            ])
            ->values();

        return Inertia::render('Customers/PricingTiers', [
            'tiers' => $tiers,
            // A few wines priced on every tier, so a rebate's effect is visible as it's set.
            'preview' => $preview->rows($models),
        ]);
    }

    public function store(StorePricingTierRequest $request, CreatePricingTierAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return back()->with('success', __('Pricing tier added.'));
    }

    public function update(UpdatePricingTierRequest $request, PricingTier $pricing_tier, UpdatePricingTierAction $action): RedirectResponse
    {
        $action->execute($pricing_tier, $request->validated());

        return back()->with('success', __('Pricing tier updated.'));
    }

    public function destroy(PricingTier $pricing_tier): RedirectResponse
    {
        $pricing_tier->delete();

        return back()->with('success', __('Pricing tier deleted. Its customers are kept, without a tier.'));
    }
}
