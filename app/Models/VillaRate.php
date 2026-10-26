<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A nightly rate for a date span (both ends inclusive). Where spans overlap, the one that starts
 * latest wins — see BookingPricing.
 *
 * @property string $id
 * @property string $villa_id
 * @property string $label
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Money $nightly_rate
 * @property int|null $min_nights
 * @property string|null $notes
 */
class VillaRate extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['villa_id', 'label', 'start_date', 'end_date', 'nightly_rate', 'min_nights', 'notes'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'nightly_rate' => MoneyCast::class,
            'min_nights' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Villa, $this>
     */
    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }
}
