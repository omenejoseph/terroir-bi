<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryCategory;
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
 * @property string $unit
 * @property string $current_stock
 * @property Money|null $default_price
 * @property Money|null $cost_per_unit
 * @property bool $is_active
 * @property bool $is_for_sale
 * @property bool $hide_from_portal
 * @property string|null $sales_unit
 * @property string|null $unit_size
 * @property int $pack_size
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
}
