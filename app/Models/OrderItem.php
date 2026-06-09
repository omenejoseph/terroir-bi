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
 * One line of an order. inventory_item_id is null for custom (non-catalog)
 * lines, which carry a custom_description instead.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string|null $inventory_item_id
 * @property int $quantity
 * @property string $unit_type
 * @property Money $unit_price
 * @property Money $total
 * @property Money|null $cost_per_unit
 * @property string|null $custom_description
 */
class OrderItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'order_id',
        'inventory_item_id',
        'quantity',
        'unit_type',
        'unit_price',
        'total',
        'cost_per_unit',
        'custom_description',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => MoneyCast::class,
            'total' => MoneyCast::class,
            'cost_per_unit' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
