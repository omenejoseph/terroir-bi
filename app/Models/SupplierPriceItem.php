<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $supplier_id
 * @property string|null $inventory_item_id
 * @property string $description
 * @property Money $unit_price
 * @property string|null $unit
 * @property Carbon|null $last_updated
 */
class SupplierPriceItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'supplier_id',
        'inventory_item_id',
        'description',
        'unit_price',
        'unit',
        'notes',
        'last_updated',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class,
            'last_updated' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
