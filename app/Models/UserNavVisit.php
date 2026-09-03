<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The most recent time a member visited a given nav page, feeding Manage
 * Shortcuts' "Recent" list (Figma `143:4179`). One row per (tenant, user,
 * nav_key) — RecordNavVisit upserts this rather than logging every visit.
 *
 * @property string $id
 * @property string $user_id
 * @property string $nav_key
 * @property Carbon $visited_at
 */
class UserNavVisit extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['user_id', 'nav_key', 'visited_at'];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
