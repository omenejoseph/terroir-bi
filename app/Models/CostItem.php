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
 * @property string $cost_id
 * @property string|null $inventory_item_id
 * @property string $description
 * @property string $quantity
 * @property Money $unit_price
 * @property Money $total
 * @property string|null $category
 */
class CostItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'cost_id', 'inventory_item_id', 'description', 'quantity', 'unit_price', 'total', 'category',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => MoneyCast::class,
            'total' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Cost, $this>
     */
    public function cost(): BelongsTo
    {
        return $this->belongsTo(Cost::class);
    }
}
