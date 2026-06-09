<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryCategory;
use App\Enums\SalesUnit;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 */
class InventoryItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'sku',
        'description',
        'category',
        'group',
        'subcategory',
        'vintage',
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
        ];
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
     * The recipe (bill of materials) producing this item.
     *
     * @return HasMany<RecipeItem, $this>
     */
    public function recipe(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'output_id');
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
     * @return HasMany<InventoryTechSheet, $this>
     */
    public function techSheets(): HasMany
    {
        return $this->hasMany(InventoryTechSheet::class);
    }
}
