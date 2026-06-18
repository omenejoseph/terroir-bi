<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VineyardApplicationType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $parcel_id
 * @property Carbon $date
 * @property VineyardApplicationType $type
 * @property int|null $phi_days
 * @property Carbon|null $phi_end_date
 */
class VineyardApplication extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['parcel_id', 'created_by_id', 'date', 'type', 'product', 'dosage', 'phi_days', 'phi_end_date', 'weather', 'note'];

    protected function casts(): array
    {
        return ['date' => 'datetime', 'type' => VineyardApplicationType::class, 'phi_days' => 'integer', 'phi_end_date' => 'date'];
    }
}
