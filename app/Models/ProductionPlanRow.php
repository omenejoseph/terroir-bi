<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanUnit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $plan_id
 * @property string $base_item_id
 * @property string|null $created_item_id
 * @property string|null $new_vintage
 * @property string $quantity
 * @property PlanUnit $plan_unit
 * @property int $sort_order
 */
class ProductionPlanRow extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['plan_id', 'base_item_id', 'created_item_id', 'new_vintage', 'quantity', 'plan_unit', 'sort_order'];

    protected $attributes = ['plan_unit' => 'bottles', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'plan_unit' => PlanUnit::class, 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function baseItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'base_item_id');
    }
}
