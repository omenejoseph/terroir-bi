<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single browser/device opted in to web push. Belongs to the GLOBAL user (not
 * tenant-scoped); the `endpoint` is the device identity. The p256dh/auth keys are
 * the browser's public key + shared secret used to encrypt the push payload.
 *
 * @property string $id
 * @property string $user_id
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property string|null $ua
 * @property Carbon|null $last_used_at
 */
class PushSubscription extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'ua',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
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
