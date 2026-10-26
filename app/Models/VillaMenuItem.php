<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something guests can order to the villa. A linked inventory item only lends its picture — ordering
 * never moves its stock, as in the old app.
 *
 * @property string $id
 * @property string $villa_id
 * @property string $category
 * @property string $name
 * @property string|null $description
 * @property Money $price
 * @property string|null $image
 * @property bool $is_active
 * @property int $sort_order
 * @property bool $vat_included
 * @property string|null $inventory_item_id
 */
class VillaMenuItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['villa_id', 'category', 'name', 'description', 'price', 'image', 'is_active', 'sort_order', 'vat_included', 'inventory_item_id'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0, 'vat_included' => true];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'vat_included' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Villa, $this>
     */
    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
