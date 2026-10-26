<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one agency pays per head for one program. When a rebate is recorded the price is derived
 * from the program's base price, so it follows the base price when that changes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $program_id
 * @property string $customer_id
 * @property Money $price_per_pax
 * @property string|null $rebate_percent
 */
class HospitalityAgencyPrice extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'program_id',
        'customer_id',
        'price_per_pax',
        'rebate_percent',
    ];

    protected function casts(): array
    {
        return [
            'price_per_pax' => MoneyCast::class,
            'rebate_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<HospitalityProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(HospitalityProgram::class, 'program_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
