<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on a stock-take: the system stock when the count started, what was counted, and the
 * difference between the two.
 *
 * @property string $id
 * @property string $check_id
 * @property string $item_id
 * @property string $system_stock
 * @property string|null $physical_count
 * @property string|null $difference
 */
class KitchenCheckItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['check_id', 'item_id', 'system_stock', 'physical_count', 'difference'];

    protected function casts(): array
    {
        return [
            'system_stock' => 'decimal:3',
            'physical_count' => 'decimal:3',
            'difference' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<KitchenItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KitchenItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<KitchenCheck, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(KitchenCheck::class, 'check_id');
    }
}
