<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lab/enology analysis recorded against a wine item.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $inventory_item_id
 * @property Carbon $analyzed_on
 * @property string|null $ph
 * @property string|null $total_acidity
 * @property string|null $volatile_acidity
 * @property string|null $alcohol
 * @property string|null $residual_sugar
 * @property string|null $free_so2
 * @property string|null $total_so2
 * @property string|null $temperature
 * @property string|null $density
 * @property string|null $tpi
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class BottleAnalysis extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'inventory_item_id',
        'analyzed_on',
        'ph',
        'total_acidity',
        'volatile_acidity',
        'alcohol',
        'residual_sugar',
        'free_so2',
        'total_so2',
        'temperature',
        'density',
        'tpi',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'analyzed_on' => 'date',
            'ph' => 'decimal:3',
            'total_acidity' => 'decimal:3',
            'volatile_acidity' => 'decimal:3',
            'alcohol' => 'decimal:3',
            'residual_sugar' => 'decimal:3',
            'free_so2' => 'decimal:3',
            'total_so2' => 'decimal:3',
            'temperature' => 'decimal:3',
            'density' => 'decimal:3',
            'tpi' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
