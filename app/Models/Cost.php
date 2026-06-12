<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostStatus;
use App\Enums\PaymentMethod;
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
 * @property Carbon $date
 * @property Money $total_amount
 * @property Money|null $vat_amount
 * @property string $category
 * @property CostStatus $status
 * @property PaymentMethod|null $payment_method
 * @property Carbon|null $paid_at
 * @property Carbon|null $due_date
 * @property string|null $supplier_id
 * @property string $created_by_id
 * @property bool $is_ai_generated
 * @property array<string, mixed>|null $ai_metadata
 */
class Cost extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'date', 'total_amount', 'vat_amount', 'category', 'description', 'reference',
        'status', 'payment_method', 'notes', 'paid_at', 'due_date', 'supplier_id', 'created_by_id',
        'is_ai_generated', 'ai_metadata',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'total_amount' => MoneyCast::class,
            'vat_amount' => MoneyCast::class,
            'status' => CostStatus::class,
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'due_date' => 'datetime',
            'is_ai_generated' => 'boolean',
            'ai_metadata' => 'array',
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
     * @return HasMany<CostItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CostItem::class);
    }

    /**
     * @return HasMany<CostAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(CostAttachment::class);
    }
}
