<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InflowStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A money-in record: a customer payment or an A/R item, optionally tied to an
 * order. Credit notes carry `is_credit_note = true` and reduce what is owed.
 *
 * @property string $id
 * @property string|null $customer_id
 * @property string|null $order_id
 * @property Carbon $date
 * @property Money $amount
 * @property InflowStatus $status
 * @property bool $is_credit_note
 * @property string|null $category
 * @property string|null $reference
 * @property string|null $payment_method
 * @property string|null $notes
 * @property Carbon|null $due_date
 * @property Carbon|null $received_at
 * @property string $created_by_id
 * @property Carbon|null $created_at
 */
class Inflow extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'customer_id',
        'order_id',
        'date',
        'amount',
        'status',
        'is_credit_note',
        'category',
        'reference',
        'payment_method',
        'notes',
        'due_date',
        'received_at',
        'created_by_id',
    ];

    protected $attributes = [
        'status' => 'PENDING',
        'is_credit_note' => false,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'amount' => MoneyCast::class,
            'status' => InflowStatus::class,
            'is_credit_note' => 'boolean',
            'due_date' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** Signed minor amount: credit notes count negatively against what's owed. */
    public function signedMinor(): int
    {
        $minor = $this->amount->getMinorAmount();

        return $this->is_credit_note ? -$minor : $minor;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
