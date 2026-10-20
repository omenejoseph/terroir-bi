<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryCategory;
use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place on the Inventory list an item sits under ("Wine"), optionally split into subcategories
 * ("Red", "White"). One level of nesting: a subcategory names its parent and has no children of its
 * own. `has_vintage` marks categories whose items are vintages of a product (wine), which turns on
 * the vintage fields on the item form and folds older vintages under the latest one on the list.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property InventoryCategory $type
 * @property string $name
 * @property bool $has_vintage
 * @property int $sort_order
 */
class ProductCategory extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['parent_id', 'type', 'name', 'has_vintage', 'sort_order'];

    protected $attributes = ['has_vintage' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'type' => InventoryCategory::class,
            'has_vintage' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ProductCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<InventoryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'product_category_id');
    }

    public function isSubcategory(): bool
    {
        return $this->parent_id !== null;
    }
}
