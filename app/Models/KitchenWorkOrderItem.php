<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a prep ticket: a kitchen item, or a free-text one ("staff meal"), with a quantity.
 *
 * @property string $id
 * @property string $work_order_id
 * @property string|null $item_id
 * @property string|null $custom_name
 * @property string $quantity
 * @property string|null $unit
 * @property string|null $notes
 * @property int $sort_order
 */
class KitchenWorkOrderItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'item_id', 'custom_name', 'quantity', 'unit', 'notes', 'sort_order'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KitchenItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KitchenItem::class, 'item_id');
    }
}
