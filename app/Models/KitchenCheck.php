<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KitchenCheckStatus;
use App\Enums\KitchenLocation;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stock-take ("Inventura"): a snapshot of what the system says, the counts people enter against
 * it, and — once completed — an adjustment for every line that did not match.
 *
 * @property string $id
 * @property Carbon $date
 * @property KitchenCheckStatus $status
 * @property KitchenLocation|null $location
 * @property string|null $note
 * @property Carbon|null $completed_at
 * @property string $user_id
 */
class KitchenCheck extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['date', 'status', 'location', 'note', 'completed_at', 'user_id'];

    protected $attributes = ['status' => 'IN_PROGRESS'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => KitchenCheckStatus::class,
            'location' => KitchenLocation::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<KitchenCheckItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KitchenCheckItem::class, 'check_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
