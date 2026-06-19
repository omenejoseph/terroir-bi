<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $inventory_item_id
 * @property StockMovementType $type
 * @property string $quantity
 * @property string|null $unit
 * @property string|null $note
 * @property string|null $reference
 * @property bool $is_reconciliation
 * @property string|null $created_by_id
 * @property Carbon|null $created_at
 */
class StockMovement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'unit',
        'note',
        'reference',
        'is_reconciliation',
        'created_by_id',
    ];

    protected $attributes = [
        'is_reconciliation' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'is_reconciliation' => 'boolean',
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
     * The user who recorded the movement (null for system/seed writes).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
