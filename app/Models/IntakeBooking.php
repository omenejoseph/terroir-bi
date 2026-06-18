<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntakeBookingStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $harvest_plan_id
 * @property Carbon $date
 * @property IntakeBookingStatus $status
 */
class IntakeBooking extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['harvest_plan_id', 'supplier_id', 'date', 'time_slot', 'grape_variety', 'estimated_kg', 'grower_name', 'status', 'notes'];

    protected $attributes = ['status' => 'SCHEDULED'];

    protected function casts(): array
    {
        return ['date' => 'datetime', 'estimated_kg' => 'decimal:3', 'status' => IntakeBookingStatus::class];
    }
}
