<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryCategory;
use App\Enums\SalesUnit;
use App\Services\Audit\Auditable;
use App\Services\Inventory\ProductCategoryResolver;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A product or material in the catalog.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $sku
 * @property InventoryCategory $category
 * @property string $unit Base/stocking unit (bottles, cases, kg, liters, units).
 * @property string $current_stock Derived from stock movements — NOT mass-assignable via the API.
 * @property Money|null $default_price
 * @property Money|null $cost_per_unit COGS per unit; required when creating an item.
 * @property bool $is_active
 * @property bool $is_for_sale
 * @property bool $hide_from_portal
 * @property string $sales_unit How the item is sold (App\Enums\SalesUnit: bottles|cases); constrains order lines.
 * @property string|null $unit_size Volume of one bottle, e.g. "750ml".
 * @property int $bottles_per_case How many bottles make up one case (used to scale case prices/COGS).
 * @property int $pack_size Generic packaging multiplier (legacy); distinct from bottles_per_case.
 * @property bool $is_auto_created
 * @property string|null $base_product_id
 * @property string|null $product_category_id
 * @property string|null $previous_vintage_id
 */
class InventoryItem extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'sku',
        'description',
        'category',
        'group',
        'subcategory',
        'product_category_id',
        'vintage',
        'previous_vintage_id',
        'unit_size',
        'unit',
        'sales_unit',
        'current_stock',
        'min_stock',
        'is_active',
        'sort_order',
        'default_price',
        'bottles_per_case',
        'pack_size',
        'is_for_sale',
        'hide_from_portal',
        'is_auto_created',
        'auto_created_at',
        'base_product_id',
        'cost_per_unit',
        'is_ai_generated',
        'ai_metadata',
    ];

    protected $attributes = [
        'current_stock' => 0,
        'is_active' => true,
        'is_for_sale' => false,
        'hide_from_portal' => false,
        'is_auto_created' => false,
        'sort_order' => 0,
        'bottles_per_case' => 12,
        'pack_size' => 1,
        'sales_unit' => SalesUnit::Bottles->value,
    ];

    protected function casts(): array
    {
        return [
            'category' => InventoryCategory::class,
            'current_stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
            'is_for_sale' => 'boolean',
            'hide_from_portal' => 'boolean',
            'is_auto_created' => 'boolean',
            'auto_created_at' => 'datetime',
            'sort_order' => 'integer',
            'bottles_per_case' => 'integer',
            'pack_size' => 'integer',
            'default_price' => MoneyCast::class,
            'cost_per_unit' => MoneyCast::class,
            'is_ai_generated' => 'boolean',
            'ai_metadata' => 'array',
        ];
    }

    /**
     * Keeps the `group` / `subcategory` text in step with the category record, whichever side was set:
     * choosing a category writes the names; naming a group (API, bulk import, a cloned vintage) finds or
     * creates the category. Reports read the text, the list and the form read the record.
     */
    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item): void {
            if ($item->isDirty('product_category_id')) {
                $category = $item->product_category_id === null ? null : ProductCategory::query()->with('parent')->find($item->product_category_id);

                $item->group = $category === null ? null : ($category->parent->name ?? $category->name);
                $item->subcategory = $category?->parent !== null ? $category->name : null;

                return;
            }

            if ($item->isDirty('group') || $item->isDirty('subcategory') || (! $item->exists && $item->group !== null)) {
                $resolved = app(ProductCategoryResolver::class)->resolve($item->category->value, $item->group, $item->subcategory);

                // A subcategory text with no group can't name a category; it stays as plain text.
                $item->product_category_id = $resolved?->getKey();

                if ($resolved !== null) {
                    $item->group = $resolved->parent_id !== null ? ProductCategory::query()->find($resolved->parent_id)?->name : $resolved->name;
                    $item->subcategory = $resolved->parent_id !== null ? $resolved->name : null;
                }
            }
        });
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * The vintage this one follows (2022 for a 2023), null for the oldest.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function previousVintage(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'previous_vintage_id');
    }

    /**
     * The vintage that follows this one, null for the latest.
     *
     * @return HasOne<InventoryItem, $this>
     */
    public function nextVintage(): HasOne
    {
        return $this->hasOne(InventoryItem::class, 'previous_vintage_id');
    }

    /**
     * The base product this is a vintage/variant of (self-reference).
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function baseProduct(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'base_product_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<BottleAnalysis, $this>
     */
    public function bottleAnalyses(): HasMany
    {
        return $this->hasMany(BottleAnalysis::class);
    }

    /**
     * Runs of the Produce tab for this item, newest first.
     *
     * @return HasMany<ProductionRun, $this>
     */
    public function productionRuns(): HasMany
    {
        return $this->hasMany(ProductionRun::class)->latest();
    }

    /**
     * The recipe (bill of materials) producing this item.
     *
     * @return HasMany<RecipeItem, $this>
     */
    public function recipe(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'output_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<InventoryImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(InventoryImage::class);
    }

    /**
     * The lead image (lowest sort order) — used for list thumbnails.
     *
     * @return HasOne<InventoryImage, $this>
     */
    public function firstImage(): HasOne
    {
        return $this->hasOne(InventoryImage::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<InventoryTechSheet, $this>
     */
    public function techSheets(): HasMany
    {
        return $this->hasMany(InventoryTechSheet::class);
    }

    /**
     * @return HasMany<InventoryDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(InventoryDocument::class);
    }

    /**
     * Stock changes get their own richer `inventory_item.stock_adjusted`
     * entry (type/quantity/reference/note) via StockLedger::record() — skip
     * the generic one here so the trail doesn't carry two entries for the
     * same change.
     *
     * @return list<string>
     */
    protected static function auditIgnoreOnUpdate(): array
    {
        return ['current_stock'];
    }
}
