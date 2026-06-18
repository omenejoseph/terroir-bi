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
 * @property string|null $brix
 * @property string|null $ph
 */
class MaturitySample extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['parcel_id', 'created_by_id', 'date', 'brix', 'ph', 'total_acidity', 'temperature', 'note'];

    protected function casts(): array
    {
        return [
            'date' => 'datetime', 'brix' => 'decimal:2', 'ph' => 'decimal:2',
            'total_acidity' => 'decimal:2', 'temperature' => 'decimal:2',
        ];
    }
}
