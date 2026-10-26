<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Inventory\CreateProductCategoryAction;
use App\Enums\InventoryCategory;
use App\Models\PricingTier;
use App\Models\ProductCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The setup welcome step: stores the three answers and creates what the
 * admin ticked from the suggestions they produced — top-level finished-goods
 * categories and pricing tiers. Anything that already exists (same name,
 * any case) is left as it is, so answering twice never duplicates.
 */
class ApplyWelcomeAnswersAction
{
    /** Channel key (App\Enums\CustomerType::channelKey()) => the tier it suggests. */
    public const CHANNEL_TIERS = [
        'wholesale' => 'Wholesale',
        'retail' => 'Retail',
        'agency' => 'Agency',
        'shipshop' => 'Ship & shop',
    ];

    /** The wine categories every winery starts from — all vintage-tracked. */
    public const WINE_CATEGORIES = ['Red', 'White', 'Rosé', 'Sparkling'];

    /** Suggested on top of the wines when the winery also sells other things. */
    public const OTHER_CATEGORY = 'Other products';

    public function __construct(
        private readonly CreateProductCategoryAction $createCategory,
        private readonly UpdateOnboardingStateAction $state,
    ) {}

    /**
     * @param  array{sells: string, channels?: list<string>, has_file?: bool, categories?: list<array{name: string, has_vintage?: bool}>, tiers?: list<array{name: string, rebate_percent?: numeric-string|int|float|null}>}  $answers
     * @return array{categories: int, tiers: int} how many were created
     */
    public function execute(Tenant $tenant, array $answers): array
    {
        return DB::transaction(function () use ($tenant, $answers): array {
            $categories = 0;

            foreach ($answers['categories'] ?? [] as $category) {
                if ($this->categoryExists($category['name'])) {
                    continue;
                }

                $this->createCategory->execute([
                    'name' => $category['name'],
                    'type' => InventoryCategory::Finished->value,
                    'has_vintage' => (bool) ($category['has_vintage'] ?? false),
                ]);
                $categories++;
            }

            $tiers = 0;
            $existingTiers = PricingTier::query()->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->all();

            foreach ($answers['tiers'] ?? [] as $tier) {
                $name = trim($tier['name']);

                if (in_array(mb_strtolower($name), $existingTiers, true)) {
                    continue;
                }

                PricingTier::create(['name' => $name, 'rebate_percent' => (string) ($tier['rebate_percent'] ?? 0)]);
                $existingTiers[] = mb_strtolower($name);
                $tiers++;
            }

            $this->state->answer($tenant, [
                'sells' => $answers['sells'],
                'channels' => array_values($answers['channels'] ?? []),
                'has_file' => (bool) ($answers['has_file'] ?? false),
            ]);

            return ['categories' => $categories, 'tiers' => $tiers];
        });
    }

    private function categoryExists(string $name): bool
    {
        return ProductCategory::query()
            ->where('type', InventoryCategory::Finished->value)
            ->whereNull('parent_id')
            ->get()
            ->contains(fn (ProductCategory $c): bool => mb_strtolower($c->name) === mb_strtolower(trim($name)));
    }
}
