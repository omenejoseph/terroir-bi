<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionPlanStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property ProductionPlanStatus $status
 * @property Carbon|null $confirmed_at
 */
class ProductionPlan extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['created_by_id', 'name', 'status', 'confirmed_at', 'notes'];

    protected $attributes = ['status' => 'DRAFT'];

    protected function casts(): array
    {
        return ['status' => ProductionPlanStatus::class, 'confirmed_at' => 'datetime'];
    }

    /** @return HasMany<ProductionPlanRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ProductionPlanRow::class, 'plan_id');
    }
}
