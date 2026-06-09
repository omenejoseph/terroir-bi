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
 * @property string $id
 * @property string $supplier_order_id
 * @property string|null $inventory_item_id
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property Money $unit_price
 * @property Money $total
 */
class SupplierOrderItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'supplier_order_id', 'inventory_item_id', 'description', 'quantity', 'unit', 'unit_price', 'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => MoneyCast::class,
            'total' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
