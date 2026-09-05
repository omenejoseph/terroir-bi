/**
 * Types for the platform-admin ("/admin") section — the Inertia port of the
 * retired Filament back office. Grows alongside app/Http/Controllers/Web/Admin/**
 * as each tier lands.
 */

export interface TranslationOverride {
    id: string;
    locale: string;
    key: string;
    value: string;
    updated_at?: string;
}

export interface PlatformAdmin {
    id: string;
    name: string;
    email: string;
    created_at: string | null;
}

/** One row of Admin/Users/Index.vue — App\Http\Controllers\Web\Admin\UserController::row(). */
export interface AdminUserSummary {
    id: string;
    name: string;
    email: string;
    is_platform_admin: boolean;
    tenants_count: number;
    created_at: string | null;
}

export interface AdminUserMembership {
    tenant_name: string | null;
    /** App\Enums\TenantRole values, e.g. 'ADMIN'. */
    roles: string[];
    /** App\Enums\MembershipStatus value. */
    status: string;
}

export interface AdminUserDetail extends AdminUserSummary {
    memberships: AdminUserMembership[];
}

export interface AdminOption {
    value: string;
    label: string;
}

/** One row of Admin/Plans/** — App\Http\Controllers\Web\Admin\PlanController::row(). */
export interface AdminPlan {
    id: string;
    name: string;
    slug: string;
    /** Integer minor units, or null for a free/internal plan. */
    price_minor: number | null;
    /** Major-unit decimal string (e.g. "15.00"), or null. Same shape the form edits. */
    price_major: string | null;
    currency: string;
    /** App\Enums\Module keys. */
    modules: string[];
    stripe_price_id: string | null;
    trial_days: number;
    grace_full_days: number;
    grace_readonly_days: number;
    interval: string;
    is_active: boolean;
    is_public: boolean;
    tenants_count: number;
}

export interface AdminPlanTenant {
    id: string;
    name: string;
    slug: string;
    status: string;
    stripe_status: string | null;
}

export interface AdminTenantSubscription {
    stripe_status: string | null;
    stripe_customer_id: string | null;
    stripe_subscription_id: string | null;
    stripe_price_id: string | null;
    trial_ends_at: string | null;
    current_period_end: string | null;
    canceled_at: string | null;
}

export interface AdminTenantPlan {
    id: string;
    name: string;
    /** Major-unit decimal + currency + interval (e.g. "15.00 EUR / month"), or null if free/no plan. */
    price_label: string | null;
    /** App\Enums\Module labels. */
    modules: string[];
}

/** One row of Admin/Tenants/** — App\Http\Controllers\Web\Admin\TenantController::row(). */
export interface AdminTenant {
    id: string;
    name: string;
    slug: string;
    /** App\Enums\TenantStatus value. */
    status: string;
    plan: AdminTenantPlan | null;
    plan_id: string | null;
    /** App\Enums\AccessLevel value, computed server-side. */
    access: string;
    subscription: AdminTenantSubscription | null;
    /** Whether "Generate/Email subscription link" applies — a paid plan with no Stripe subscription yet. */
    needs_subscription: boolean;
}

export interface AdminTenantMember {
    id: string;
    name: string;
    email: string | null;
    /** App\Enums\TenantRole values. */
    roles: string[];
    /** App\Enums\MembershipStatus value. */
    status: string;
}
