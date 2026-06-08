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
 * @property string $inventory_item_id
 * @property string $pricing_tier_id
 * @property Money $price
 */
class TierPrice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'inventory_item_id',
        'pricing_tier_id',
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
     * @return BelongsTo<PricingTier, $this>
     */
    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class);
    }
}
