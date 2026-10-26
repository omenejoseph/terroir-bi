<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ports existing inventory to the category + vintage-chain model. Written against the query builder
 * (not the models) so it runs from a migration, outside any tenant context, across every tenant.
 *
 *  1. Every distinct group (and subcategory under it) becomes a `product_categories` row, in the
 *     order the organisation had arranged them (`inventory_group_orders`), then alphabetically.
 *  2. Each item is linked to its leaf category, and its group / subcategory text is normalised to the
 *     category's name (so "wine" and "Wine " no longer split into two bands).
 *  3. A category is flagged `has_vintage` when any of its items carries a vintage.
 *  4. A wine's vintages — same type, category, name and bottle size — are chained oldest → newest
 *     through `previous_vintage_id`. Duplicates of the same year and non-year vintages ("NV") are
 *     left unlinked rather than guessed at.
 *
 * Idempotent: items already linked are skipped, existing categories are reused and existing chain
 * links are kept.
 */
class ProductCategoryBackfill
{
    /** @var array<string, string> "tenant|type|parent|name" (lowercased) → category id */
    private array $categoryIds = [];

    /** @var array<string, string> category id → name as stored */
    private array $names = [];

    /** @return array{categories: int, linked: int, chained: int} */
    public function run(?string $tenantId = null): array
    {
        $this->categoryIds = [];
        $this->names = [];

        $created = 0;
        $linked = 0;

        $query = DB::table('inventory_items')
            ->select(['id', 'tenant_id', 'category', 'group', 'subcategory', 'vintage', 'name', 'unit_size', 'product_category_id', 'previous_vintage_id', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $items = $query->get();

        $this->loadExistingCategories($tenantId);

        $orders = $this->arrangements($tenantId);

        // 1. Categories --------------------------------------------------------------------------
        /** @var array<string, array{tenant: string, type: string, name: string, subs: array<string, string>}> $wanted */
        $wanted = [];
        foreach ($items as $item) {
            $group = $this->clean($item->group);
            if ($item->product_category_id !== null || $group === null) {
                continue;
            }

            $key = $item->tenant_id.'|'.$item->category.'|'.mb_strtolower($group);
            $wanted[$key] ??= ['tenant' => $item->tenant_id, 'type' => $item->category, 'name' => $group, 'subs' => []];

            $sub = $this->clean($item->subcategory);
            if ($sub !== null) {
                $wanted[$key]['subs'][mb_strtolower($sub)] ??= $sub;
            }
        }

        // Arranged ones first in their saved order, the rest alphabetically — per tenant and type.
        $byScope = [];
        foreach ($wanted as $group) {
            $byScope[$group['tenant'].'|'.$group['type']][] = $group;
        }

        foreach ($byScope as $groups) {
            usort($groups, fn (array $a, array $b): int => $this->position($orders, $a, null) <=> $this->position($orders, $b, null)
                ?: strcasecmp($a['name'], $b['name']));

            foreach ($groups as $group) {
                [$id, $wasCreated] = $this->category($group['tenant'], $group['type'], null, $group['name']);
                $created += (int) $wasCreated;

                $subs = $group['subs'];
                uasort($subs, fn (string $a, string $b): int => ($orders[$this->orderKey($group['tenant'], $group['type'], $group['name'], $a)] ?? 1000000)
                    <=> ($orders[$this->orderKey($group['tenant'], $group['type'], $group['name'], $b)] ?? 1000000)
                    ?: strcasecmp($a, $b));

                foreach ($subs as $sub) {
                    $created += (int) $this->category($group['tenant'], $group['type'], $id, $sub)[1];
                }
            }
        }

        // 2 + 3. Link items, normalise their text, flag categories that carry vintages ------------
        $vintageCategories = [];
        foreach ($items as $item) {
            $group = $this->clean($item->group);
            if ($item->product_category_id !== null || $group === null) {
                continue;
            }

            $topId = $this->categoryIds[$this->idKey($item->tenant_id, $item->category, null, $group)];
            $sub = $this->clean($item->subcategory);
            $leafId = $sub === null ? $topId : $this->categoryIds[$this->idKey($item->tenant_id, $item->category, $topId, $sub)];

            DB::table('inventory_items')->where('id', $item->id)->update([
                'product_category_id' => $leafId,
                'group' => $this->canonicalName($topId),
                'subcategory' => $sub === null ? null : $this->canonicalName($leafId),
            ]);
            $linked++;

            if ($this->clean($item->vintage) !== null) {
                $vintageCategories[$topId] = true;
                $vintageCategories[$leafId] = true;
            }
        }

        if ($vintageCategories !== []) {
            DB::table('product_categories')->whereIn('id', array_keys($vintageCategories))->update(['has_vintage' => true]);
        }

        return ['categories' => $created, 'linked' => $linked, 'chained' => $this->chainVintages($tenantId)];
    }

    /** Chains each wine's year vintages oldest → newest. Returns how many links were written. */
    private function chainVintages(?string $tenantId): int
    {
        $query = DB::table('inventory_items')
            ->select(['id', 'tenant_id', 'category', 'group', 'subcategory', 'vintage', 'name', 'unit_size', 'previous_vintage_id', 'created_at'])
            ->whereNotNull('vintage')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $rows = $query->get();

        $taken = DB::table('inventory_items')->whereNotNull('previous_vintage_id')->pluck('previous_vintage_id')->flip()->all();

        $families = [];
        foreach ($rows as $row) {
            $vintage = trim((string) $row->vintage);
            if (preg_match('/^\d{4}$/', $vintage) !== 1) {
                continue;
            }

            $key = implode('|', [
                $row->tenant_id, $row->category,
                mb_strtolower(trim((string) $row->group)), mb_strtolower(trim((string) $row->subcategory)),
                mb_strtolower(trim((string) $row->name)), mb_strtolower(trim((string) $row->unit_size)),
            ]);

            // Two items of the same year are duplicates, not a chain — the first keeps the slot.
            $families[$key][$vintage] ??= $row;
        }

        $chained = 0;
        foreach ($families as $byYear) {
            ksort($byYear);
            $ordered = array_values($byYear);

            for ($i = 1; $i < count($ordered); $i++) {
                $older = $ordered[$i - 1];
                $newer = $ordered[$i];

                if ($newer->previous_vintage_id !== null || isset($taken[$older->id])) {
                    continue;
                }

                DB::table('inventory_items')->where('id', $newer->id)->update(['previous_vintage_id' => $older->id]);
                $taken[$older->id] = true;
                $chained++;
            }
        }

        return $chained;
    }

    private function loadExistingCategories(?string $tenantId): void
    {
        $query = DB::table('product_categories')->select(['id', 'tenant_id', 'type', 'parent_id', 'name']);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($query->get() as $row) {
            $this->categoryIds[$this->idKey($row->tenant_id, $row->type, $row->parent_id, $row->name)] = $row->id;
            $this->names[$row->id] = $row->name;
        }
    }

    /**
     * Finds or creates one category row. New rows go after any existing siblings.
     *
     * @return array{0: string, 1: bool} id, and whether it was created now
     */
    private function category(string $tenantId, string $type, ?string $parentId, string $name): array
    {
        $key = $this->idKey($tenantId, $type, $parentId, $name);

        if (isset($this->categoryIds[$key])) {
            return [$this->categoryIds[$key], false];
        }

        $id = (string) Str::ulid();
        $siblings = DB::table('product_categories')
            ->where('tenant_id', $tenantId)->where('type', $type)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->count();

        DB::table('product_categories')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'parent_id' => $parentId,
            'type' => $type,
            'name' => $name,
            'has_vintage' => false,
            'sort_order' => $siblings,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->names[$id] = $name;

        return [$this->categoryIds[$key] = $id, true];
    }

    /**
     * The saved drag-and-drop order, keyed by tenant|type|group|subcategory (lowercased).
     *
     * @return array<string, int>
     */
    private function arrangements(?string $tenantId): array
    {
        if (! Schema::hasTable('inventory_group_orders')) {
            return [];
        }

        $query = DB::table('inventory_group_orders');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $orders = [];
        foreach ($query->get() as $row) {
            $orders[$this->orderKey($row->tenant_id, $row->category, $row->group_name, $row->subcategory)] = (int) $row->sort_order;
        }

        return $orders;
    }

    /**
     * @param  array<string, int>  $orders
     * @param  array{tenant: string, type: string, name: string, subs: array<string, string>}  $group
     */
    private function position(array $orders, array $group, ?string $sub): int
    {
        return $orders[$this->orderKey($group['tenant'], $group['type'], $group['name'], $sub ?? '')] ?? 1000000;
    }

    private function orderKey(string $tenantId, string $type, string $group, ?string $sub): string
    {
        return implode('|', [$tenantId, $type, mb_strtolower($group), mb_strtolower((string) $sub)]);
    }

    private function idKey(string $tenantId, string $type, ?string $parentId, string $name): string
    {
        return implode('|', [$tenantId, $type, (string) $parentId, mb_strtolower($name)]);
    }

    private function canonicalName(string $categoryId): string
    {
        return $this->names[$categoryId];
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
