<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhenologyStage;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $parcel_id
 * @property Carbon $date
 * @property PhenologyStage $stage
 * @property string|null $progress_percent
 */
class PhenologyLog extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['parcel_id', 'created_by_id', 'date', 'stage', 'progress_percent', 'photo_url', 'note'];

    protected function casts(): array
    {
        return ['date' => 'datetime', 'stage' => PhenologyStage::class, 'progress_percent' => 'decimal:2'];
    }
}
