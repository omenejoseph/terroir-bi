<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pinned nav-item key (Manage Shortcuts, Figma `143:4179`) for a member of
 * this tenant. `nav_key` is opaque here — it is validated against
 * App\Support\NavCatalog::ALL_KEYS by SetPinnedShortcutsAction, and resolved
 * to a label/icon/href only on the frontend, in NAV_CATEGORIES.
 *
 * @property string $id
 * @property string $user_id
 * @property string $nav_key
 * @property int $position
 */
class UserShortcut extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['user_id', 'nav_key', 'position'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
