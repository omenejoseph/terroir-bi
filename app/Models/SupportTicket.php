<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A support request from someone in a tenant to the platform team.
 *
 * Deliberately NOT BelongsToTenant: platform admins work one queue across
 * every tenant from /admin, where no tenant is bound. The tenant side must
 * therefore always narrow by tenant_id itself — see scopeForTenant() and
 * Web\SupportTicketController.
 *
 * @property string $id
 * @property int $number
 * @property string $tenant_id
 * @property string|null $requester_id
 * @property string|null $assignee_id
 * @property string $subject
 * @property SupportTicketCategory $category
 * @property SupportTicketPriority $priority
 * @property SupportTicketStatus $status
 * @property string|null $page_url
 * @property Carbon|null $last_message_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read User|null $requester
 * @property-read User|null $assignee
 * @property-read Collection<int, SupportTicketMessage> $messages
 */
class SupportTicket extends Model
{
    use HasUlids;

    protected $fillable = [
        'number',
        'tenant_id',
        'requester_id',
        'assignee_id',
        'subject',
        'category',
        'priority',
        'status',
        'page_url',
        'last_message_at',
        'resolved_at',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'category' => SupportTicketCategory::class,
            'priority' => SupportTicketPriority::class,
            'status' => SupportTicketStatus::class,
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
