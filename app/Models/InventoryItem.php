<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryCategory;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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
        'unit',
        'current_stock',
        'min_stock',
        'is_active',
        'sort_order',
        'default_price',
        'bottles_per_case',
        'is_for_sale',
        'cost_per_unit',
    ];

    protected $attributes = [
        'current_stock' => 0,
        'is_active' => true,
        'is_for_sale' => false,
        'sort_order' => 0,
        'bottles_per_case' => 12,
    ];

    protected function casts(): array
    {
        return [
            'category' => InventoryCategory::class,
            'current_stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
            'is_for_sale' => 'boolean',
            'sort_order' => 'integer',
            'bottles_per_case' => 'integer',
            'default_price' => MoneyCast::class,
            'cost_per_unit' => MoneyCast::class,
        ];
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
}
