<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Audit record of one physical stocktake: who ran it, and the adjustments made.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $performed_by_id
 * @property string $reference
 * @property int $items_counted
 * @property int $items_adjusted
 * @property string $net_difference
 * @property Carbon|null $created_at
 */
class InventoryCheck extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'performed_by_id',
        'reference',
        'items_counted',
        'items_adjusted',
        'net_difference',
    ];

    protected function casts(): array
    {
        return [
            'items_counted' => 'integer',
            'items_adjusted' => 'integer',
            'net_difference' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    /**
     * @return HasMany<InventoryCheckLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCheckLine::class);
    }
}
