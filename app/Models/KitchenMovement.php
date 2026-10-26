<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KitchenMovementType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a kitchen item's stock. `quantity` is the signed change, whatever the type, so the
 * movements of an item add up to its stock.
 *
 * @property string $id
 * @property string $item_id
 * @property KitchenMovementType $type
 * @property string $quantity
 * @property string|null $note
 * @property string $user_id
 * @property Carbon|null $created_at
 */
class KitchenMovement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['item_id', 'type', 'quantity', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'type' => KitchenMovementType::class,
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<KitchenItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KitchenItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
