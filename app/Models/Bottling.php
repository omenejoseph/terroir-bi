<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bottling run that draws volume from a lot into finished inventory.
 *
 * @property string $id
 * @property string $wine_lot_id
 * @property string|null $inventory_item_id
 * @property string $created_by_id
 * @property int $bottle_count
 * @property int $bottle_volume_ml
 * @property string $volume_used
 * @property Carbon $date
 * @property string|null $note
 */
class Bottling extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'wine_lot_id', 'inventory_item_id', 'created_by_id', 'bottle_count',
        'bottle_volume_ml', 'volume_used', 'date', 'note',
    ];

    protected $attributes = [
        'bottle_volume_ml' => 750,
    ];

    protected function casts(): array
    {
        return [
            'bottle_count' => 'integer',
            'bottle_volume_ml' => 'integer',
            'volume_used' => 'decimal:3',
            'date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function wineLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
