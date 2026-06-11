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
 * An immutable audit row: a supplier price-list line's cost was set or changed.
 * Written automatically by {@see SupplierPriceItem} on create / price update.
 *
 * @property string $supplier_id
 * @property string $description
 * @property string|null $unit
 * @property Money|null $old_price
 * @property Money $new_price
 * @property Carbon|null $created_at
 */
class SupplierPriceChange extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null; // append-only log — created_at only

    protected $fillable = [
        'supplier_id',
        'supplier_price_item_id',
        'description',
        'unit',
        'old_price',
        'new_price',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => MoneyCast::class,
            'new_price' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
