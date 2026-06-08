<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One input line of an item's bill of materials.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $output_id
 * @property string $input_id
 * @property string $quantity
 */
class RecipeItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'output_id',
        'input_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function output(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'output_id');
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function input(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'input_id');
    }
}
