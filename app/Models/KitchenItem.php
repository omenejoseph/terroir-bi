<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KitchenCategory;
use App\Enums\KitchenLocation;
use App\Enums\KitchenUnit;
use App\Services\Audit\Auditable;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the kitchen keeps in stock (flour, a sauce base, a plated dessert). Stock only moves
 * through KitchenStockLedger, so every change leaves a movement.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property KitchenCategory $category
 * @property string|null $group
 * @property KitchenUnit $unit
 * @property string $current_stock
 * @property string|null $min_stock
 * @property Money|null $cost_per_unit
 * @property string|null $supplier
 * @property KitchenLocation $location
 * @property bool $is_active
 * @property int $sort_order
 */
class KitchenItem extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'category',
        'group',
        'unit',
        'min_stock',
        'cost_per_unit',
        'supplier',
        'location',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'current_stock' => '0.000',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => KitchenCategory::class,
            'unit' => KitchenUnit::class,
            'location' => KitchenLocation::class,
            'current_stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'cost_per_unit' => MoneyCast::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Stock changes are recorded as movements by the ledger, not as audit entries.
     *
     * @return list<string>
     */
    protected static function auditIgnoreOnUpdate(): array
    {
        return ['current_stock'];
    }

    /**
     * @return HasMany<KitchenMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(KitchenMovement::class, 'item_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isLow(): bool
    {
        return $this->min_stock !== null && (float) $this->current_stock < (float) $this->min_stock;
    }
}
