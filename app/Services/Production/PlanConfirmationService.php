<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\ProductionPlanStatus;
use App\Models\InventoryItem;
use App\Models\ProductionPlan;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a production plan. Rows that introduce a new vintage either link to an
 * existing item of that name+vintage or auto-create a draft inventory item
 * (cloning the base item's recipe and lineage). Without `force`, name+vintage
 * collisions are reported so the user can decide.
 */
class PlanConfirmationService
{
    /**
     * @return array{plan: ProductionPlan, conflicts: list<array<string, mixed>>}
     */
    public function confirm(ProductionPlan $plan, bool $force): array
    {
        return DB::transaction(function () use ($plan, $force): array {
            $conflicts = [];

            foreach ($plan->rows()->with('baseItem')->get() as $row) {
                $base = $row->baseItem;
                if (! $base instanceof InventoryItem || $row->new_vintage === null || $row->new_vintage === '') {
                    continue;
                }

                $existing = InventoryItem::query()
                    ->where('name', $base->name)
                    ->where('vintage', $row->new_vintage)
                    ->where('id', '!=', $base->getKey())
                    ->first();

                if ($existing !== null) {
                    if (! $force) {
                        $conflicts[] = ['row_id' => $row->getKey(), 'name' => $base->name, 'vintage' => $row->new_vintage, 'existing_id' => $existing->getKey()];

                        continue;
                    }
                    $row->update(['created_item_id' => $existing->getKey()]);

                    continue;
                }

                $created = $this->cloneItem($base, $row->new_vintage);
                $row->update(['created_item_id' => $created->getKey()]);
            }

            if (! $force && $conflicts !== []) {
                return ['plan' => $plan, 'conflicts' => $conflicts];
            }

            $plan->update(['status' => ProductionPlanStatus::Confirmed, 'confirmed_at' => now()]);

            return ['plan' => $plan->refresh(), 'conflicts' => []];
        });
    }

    private function cloneItem(InventoryItem $base, string $vintage): InventoryItem
    {
        $sku = $this->uniqueSku($base->sku.'-'.$vintage);

        $created = InventoryItem::create([
            'name' => $base->name,
            'sku' => $sku,
            'description' => $base->description,
            'category' => $base->category,
            'group' => $base->group,
            'subcategory' => $base->subcategory,
            'vintage' => $vintage,
            'unit' => $base->unit,
            'sales_unit' => $base->sales_unit,
            'bottles_per_case' => $base->bottles_per_case,
            'default_price' => $base->default_price?->getMinorAmount(),
            'cost_per_unit' => $base->cost_per_unit?->getMinorAmount(),
            'is_active' => false,
            'is_auto_created' => true,
            'auto_created_at' => now(),
            'base_product_id' => $base->getKey(),
        ]);

        // Clone the recipe.
        foreach (RecipeItem::query()->where('output_id', $base->getKey())->get() as $line) {
            RecipeItem::create([
                'output_id' => $created->getKey(),
                'input_id' => $line->input_id,
                'quantity' => $line->quantity,
                'custom_name' => $line->custom_name,
                'custom_unit' => $line->custom_unit,
                'custom_cost' => $line->custom_cost?->getMinorAmount(),
            ]);
        }

        return $created;
    }

    private function uniqueSku(string $candidate): string
    {
        $sku = $candidate;
        $i = 2;
        while (InventoryItem::query()->where('sku', $sku)->exists()) {
            $sku = $candidate.'-'.$i;
            $i++;
        }

        return $sku;
    }
}
