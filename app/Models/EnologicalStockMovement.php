<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnologicalStockSource;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an enological product's stock. Written only through
 * App\Services\Cellar\EnologicalStockLedger.
 *
 * @property string $id
 * @property string $enological_product_id
 * @property string $delta
 * @property string $balance_after
 * @property EnologicalStockSource $source
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $note
 * @property string|null $created_by_id
 */
class EnologicalStockMovement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'enological_product_id', 'delta', 'balance_after', 'source', 'reference_type', 'reference_id', 'note', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'source' => EnologicalStockSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
