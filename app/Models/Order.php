<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A B2B sales order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property Money $total_amount
 * @property string|null $notes
 * @property string $customer_id
 * @property string $created_by_id
 * @property bool $is_backorder
 * @property Carbon|null $backorder_date
 * @property Money|null $shipping_cost
 * @property bool $shipping_paid_by_us
 * @property bool $is_consignment
 * @property Carbon|null $consignment_closed_at
 * @property Carbon|null $last_stale_notified_at
 * @property Carbon|null $created_at
 */
class Order extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'order_number',
        'status',
        'total_amount',
        'notes',
        'customer_id',
        'created_by_id',
        'is_backorder',
        'backorder_date',
        'shipping_cost',
        'shipping_paid_by_us',
        'is_consignment',
        'consignment_closed_at',
        'last_stale_notified_at',
    ];

    protected $attributes = [
        'status' => 'RECEIVED',
        'total_amount' => 0,
        'is_backorder' => false,
        'shipping_paid_by_us' => false,
        'is_consignment' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => MoneyCast::class,
            'shipping_cost' => MoneyCast::class,
            'is_backorder' => 'boolean',
            'shipping_paid_by_us' => 'boolean',
            'is_consignment' => 'boolean',
            'backorder_date' => 'datetime',
            'consignment_closed_at' => 'datetime',
            'last_stale_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function orderNotes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /**
     * @return HasMany<ConsignmentReport, $this>
     */
    public function consignmentReports(): HasMany
    {
        return $this->hasMany(ConsignmentReport::class);
    }
}
