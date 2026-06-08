<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\DataTransferObjects\InvitationData;
use App\Enums\TenantRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates (or refreshes) a pending invitation for an email to join the current
 * tenant with the given roles. Runs in tenant context, so the tenant_id is set
 * automatically by BelongsToTenant. Returns the DTO including the one-time
 * plaintext accept token.
 */
class InviteMemberAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<TenantRole>  $roles
     */
    public function execute(string $email, array $roles, ?User $invitedBy): InvitationData
    {
        $email = Str::lower($email);
        $this->assertNotAlreadyMember($email);

        $plainToken = Str::random(48);

        $invitation = Invitation::updateOrCreate(
            ['email' => $email],
            [
                'roles' => collect($roles),
                'token' => hash('sha256', $plainToken),
                'invited_by' => $invitedBy?->getKey(),
                'expires_at' => now()->addDays(14),
                'accepted_at' => null,
            ],
        );

        return InvitationData::fromModel($invitation, $plainToken);
    }

    private function assertNotAlreadyMember(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $alreadyMember = Membership::query()
            ->where('tenant_id', $this->tenant->id())
            ->where('user_id', $user->getKey())
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => __('iam.invitation_email_taken'),
            ]);
        }
    }
}
