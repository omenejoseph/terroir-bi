<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable audit row: a cash inflow was edited. Written automatically by
 * {@see Inflow} on update. `changes` holds a list of `{ field, old, new }`
 * entries with raw values — the client formats money / status / dates.
 *
 * @property string $inflow_id
 * @property array<int, array{field: string, old: mixed, new: mixed}> $changes
 * @property string|null $changed_by_id
 * @property Carbon|null $created_at
 */
class InflowChange extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null; // append-only log — created_at only

    protected $fillable = [
        'inflow_id',
        'changes',
        'changed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Inflow, $this>
     */
    public function inflow(): BelongsTo
    {
        return $this->belongsTo(Inflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
