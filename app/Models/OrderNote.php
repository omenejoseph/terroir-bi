<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A threaded comment on an order.
 *
 * @property string $id
 * @property string $order_id
 * @property string $content
 * @property string $author_id
 * @property Carbon|null $created_at
 */
class OrderNote extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'order_id',
        'content',
        'author_id',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
