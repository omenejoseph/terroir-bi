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
 * One input line of an item's bill of materials. A line is either backed by a
 * catalog item (input_id) or a custom ingredient (custom_name/unit/cost).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $output_id
 * @property string|null $input_id
 * @property string $quantity
 * @property string|null $custom_name
 * @property string|null $custom_unit
 * @property Money|null $custom_cost
 */
class RecipeItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'output_id',
        'input_id',
        'quantity',
        'custom_name',
        'custom_unit',
        'custom_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'custom_cost' => MoneyCast::class,
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
