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
 * An absolute negotiated price for one customer + item. Rebate is NOT applied
 * on top of this (pricing engine §5.2).
 *
 * @property string $id
 * @property string $inventory_item_id
 * @property string $customer_id
 * @property Money $price
 */
class CustomerPrice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'inventory_item_id',
        'customer_id',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
