<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the Produce tab: `quantity` of the item made, and the inputs it took from stock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $inventory_item_id
 * @property string $quantity
 * @property string $reference
 * @property list<array{name: string, sku: string, unit: string, quantity: string}>|null $inputs null for runs recorded before inputs were kept
 * @property string|null $created_by_id
 */
class ProductionRun extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['inventory_item_id', 'quantity', 'reference', 'inputs', 'created_by_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'inputs' => 'array',
        ];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
