/**
 * The tenant self-service Team page (Web\TeamController). Mirrors
 * App\DataTransferObjects\MembershipData / InvitationData — the same DTOs
 * the JSON API's Api\MemberController / Api\InvitationController return.
 */

export interface TeamMember {
    id: string;
    user_id: string;
    name: string;
    email: string;
    /** App\Enums\TenantRole values, e.g. "ADMIN". A membership may hold several. */
    roles: string[];
    /** App\Enums\MembershipStatus: "active" | "suspended". */
    status: string;
    /** Set by Web\TeamController: whether the viewer may impersonate this member right now. */
    can_impersonate?: boolean;
}

export interface TeamInvitation {
    id: string;
    email: string;
    roles: string[];
    expires_at: string;
    pending: boolean;
}

export interface RoleOption {
    value: string;
    label: string;
}
