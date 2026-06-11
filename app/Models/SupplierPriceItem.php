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
     * Audit every cost change. Fires for all write paths (manual add/edit, CSV
     * import, upsert) since they all save the model. getOriginal() is still the
     * pre-save value inside the saved event (syncOriginal runs after).
     */
    protected static function booted(): void
    {
        static::created(fn (SupplierPriceItem $item) => self::recordChange($item, null));

        static::updated(function (SupplierPriceItem $item): void {
            // Compare raw minor amounts — the Money cast makes wasChanged() unreliable.
            // In the updated event, getRawOriginal() is still the pre-save value.
            $oldMinor = $item->getRawOriginal('unit_price');
            if ((int) $oldMinor === $item->unit_price->getMinorAmount()) {
                return; // no cost change
            }
            self::recordChange($item, Money::fromMinor((int) $oldMinor, $item->unit_price->getCurrencyCode()));
        });
    }

    private static function recordChange(self $item, ?Money $old): void
    {
        SupplierPriceChange::create([
            'supplier_id' => $item->supplier_id,
            'supplier_price_item_id' => $item->getKey(),
            'description' => $item->description,
            'unit' => $item->unit,
            'old_price' => $old,
            'new_price' => $item->unit_price,
        ]);
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
