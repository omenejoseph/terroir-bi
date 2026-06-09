<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-customer override of an item's catalog visibility.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $inventory_item_id
 * @property bool $visible
 */
class CustomerProductOverride extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'customer_id',
        'inventory_item_id',
        'visible',
    ];

    protected $attributes = [
        'visible' => true,
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
