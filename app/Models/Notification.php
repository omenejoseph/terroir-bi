<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property NotificationType $type
 * @property string $title
 * @property string|null $body
 * @property string|null $link
 * @property string|null $actor_id
 * @property bool $is_read
 * @property Carbon|null $created_at
 */
class Notification extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'actor_id',
        'is_read',
    ];

    protected $attributes = [
        'is_read' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'is_read' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
