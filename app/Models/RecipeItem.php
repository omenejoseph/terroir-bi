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
 * @property string|null $wine_lot_id a cellar wine this line draws on (costed per litre from the lot)
 * @property int $sort_order position among the item's recipe lines
 * @property string|null $unit the unit `quantity` is entered in (ml, cl, l, g, kg); null = the input's own unit
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
        'wine_lot_id',
        'quantity',
        'unit',
        'sort_order',
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
     * @return BelongsTo<WineLot, $this>
     */
    public function wineLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class, 'wine_lot_id');
    }

    /**
     * catalog = an inventory item, cellar = wine from a lot, custom = a free-text ingredient with its own cost.
     * A cellar line whose lot was later deleted keeps its name and last per-litre cost and reads as custom.
     */
    public function kind(): string
    {
        return match (true) {
            $this->input_id !== null => 'catalog',
            $this->wine_lot_id !== null => 'cellar',
            default => 'custom',
        };
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function input(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'input_id');
    }
}
