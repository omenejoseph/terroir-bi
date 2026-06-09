<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a consignment report. quantity is in single bottles.
 *
 * @property string $id
 * @property string $report_id
 * @property string $order_item_id
 * @property string|null $inventory_item_id
 * @property int $quantity
 * @property Money $unit_price
 * @property Money $total
 */
class ConsignmentReportItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'report_id',
        'order_item_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => MoneyCast::class,
            'total' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<ConsignmentReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ConsignmentReport::class, 'report_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
