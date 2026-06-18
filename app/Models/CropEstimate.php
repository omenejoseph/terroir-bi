<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $parcel_id
 * @property Carbon $date
 * @property int $cluster_count
 * @property string $avg_cluster_weight
 * @property int $sample_vine_count
 * @property string $estimated_yield_kg
 */
class CropEstimate extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['parcel_id', 'created_by_id', 'date', 'cluster_count', 'avg_cluster_weight', 'sample_vine_count', 'estimated_yield_kg', 'note'];

    protected function casts(): array
    {
        return [
            'date' => 'datetime', 'cluster_count' => 'integer', 'avg_cluster_weight' => 'decimal:2',
            'sample_vine_count' => 'integer', 'estimated_yield_kg' => 'decimal:3',
        ];
    }
}
