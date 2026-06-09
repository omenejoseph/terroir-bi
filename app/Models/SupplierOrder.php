<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierOrderStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_number
 * @property SupplierOrderStatus $status
 * @property Money $total_amount
 * @property Carbon|null $sent_at
 * @property Carbon|null $expected_at
 * @property Carbon|null $received_at
 * @property string $supplier_id
 * @property string $created_by_id
 */
class SupplierOrder extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'order_number', 'status', 'total_amount', 'notes',
        'sent_at', 'expected_at', 'received_at', 'supplier_id', 'created_by_id',
    ];

    protected $attributes = [
        'status' => 'DRAFT',
        'total_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierOrderStatus::class,
            'total_amount' => MoneyCast::class,
            'sent_at' => 'datetime',
            'expected_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<SupplierOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }
}
