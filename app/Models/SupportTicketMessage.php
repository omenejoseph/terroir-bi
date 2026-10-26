<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message on a support ticket. `is_internal` notes are written by
 * platform admins for each other and are never sent to the tenant.
 *
 * @property string $id
 * @property string $support_ticket_id
 * @property string|null $author_id
 * @property string $body
 * @property bool $is_staff
 * @property bool $is_internal
 * @property Carbon|null $created_at
 * @property-read User|null $author
 * @property-read SupportTicket|null $ticket
 */
class SupportTicketMessage extends Model
{
    use HasUlids;

    protected $fillable = [
        'support_ticket_id',
        'author_id',
        'body',
        'is_staff',
        'is_internal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
            'is_internal' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
