<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InflowStatus;
use App\Enums\PaymentMethod;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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
 * @property PaymentMethod|null $payment_method
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
        'is_ai_generated',
        'ai_metadata',
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
            'payment_method' => PaymentMethod::class,
            'is_credit_note' => 'boolean',
            'due_date' => 'datetime',
            'received_at' => 'datetime',
            'is_ai_generated' => 'boolean',
            'ai_metadata' => 'array',
        ];
    }

    /** Fields whose edits are recorded in the change history. */
    private const TRACKED_FIELDS = [
        'amount', 'status', 'category', 'reference', 'customer_id', 'order_id',
        'payment_method', 'date', 'due_date', 'notes', 'is_credit_note',
    ];

    protected static function booted(): void
    {
        static::updated(function (Inflow $inflow): void {
            $changes = [];
            foreach (self::TRACKED_FIELDS as $field) {
                [$old, $new] = self::fieldValues($inflow, $field);
                if ($old !== $new) {
                    $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
                }
            }

            if ($changes === []) {
                return;
            }

            InflowChange::create([
                'inflow_id' => $inflow->getKey(),
                'changes' => $changes,
                'changed_by_id' => Auth::id(),
            ]);
        });
    }

    /**
     * Old (pre-update) and new value of a tracked field, normalised to a
     * comparable scalar. MoneyCast makes wasChanged() unreliable, so amounts are
     * compared on raw minor units (mirrors SupplierPriceItem).
     *
     * @return array{0: mixed, 1: mixed}
     */
    private static function fieldValues(self $inflow, string $field): array
    {
        $rawOld = $inflow->getRawOriginal($field);

        return match ($field) {
            'amount' => [$rawOld !== null ? (int) $rawOld : null, $inflow->amount->getMinorAmount()],
            'status' => [$rawOld, $inflow->status->value],
            'payment_method' => [$rawOld, $inflow->payment_method?->value],
            'is_credit_note' => [(bool) $rawOld, (bool) $inflow->is_credit_note],
            'date', 'due_date' => [self::dateString($rawOld), $inflow->{$field}?->toDateString()],
            default => [$rawOld, $inflow->getAttribute($field)],
        };
    }

    private static function dateString(mixed $raw): ?string
    {
        return $raw === null || $raw === '' ? null : Carbon::parse((string) $raw)->toDateString();
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

    /**
     * @return HasMany<InflowChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(InflowChange::class);
    }
}
