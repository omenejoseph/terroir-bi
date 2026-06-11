<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One adjusted item within an inventory check (system vs physical count).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $inventory_check_id
 * @property string|null $inventory_item_id
 * @property string $name
 * @property string $sku
 * @property string $system_count
 * @property string $physical_count
 * @property string $difference
 */
class InventoryCheckLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'inventory_check_id',
        'inventory_item_id',
        'name',
        'sku',
        'system_count',
        'physical_count',
        'difference',
    ];

    protected function casts(): array
    {
        return [
            'system_count' => 'decimal:3',
            'physical_count' => 'decimal:3',
            'difference' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<InventoryCheck, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(InventoryCheck::class, 'inventory_check_id');
    }
}
