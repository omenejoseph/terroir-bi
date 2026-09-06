/**
 * Types for the props every Inertia page receives.
 *
 * These mirror App\Http\Middleware\HandleInertiaRequests::share(). When you add
 * a shared prop there, add it here too — `usePage<SharedProps>()` is the only
 * thing standing between a renamed prop and a runtime undefined.
 */

export interface User {
    id: string;
    name: string;
    email: string;
    /** Gates the "Admin" link into /admin (AppSidebar.vue) — see useAuth().isPlatformAdmin. */
    is_platform_admin: boolean;
}

export interface TenantSummary {
    id: string;
    name: string;
    slug: string;
}

/** One of the current user's tenant memberships (feeds the tenant switcher). */
export interface TenantMembership {
    tenant_id: string;
    name: string;
    slug: string;
    roles: string[];
    status: string;
}

export interface Auth {
    user: User | null;
    roles: string[];
    /** Resolved server-side from RoleCapabilities; may contain the '*' wildcard. */
    capabilities: string[];
    /** Ordered nav-item keys pinned via Manage Shortcuts (Figma `143:4179`). */
    shortcuts: string[];
}

export interface Flash {
    success: string | null;
    error: string | null;
}

/** Org-wide settings (App\DataTransferObjects\OrganizationSettingsData). */
export interface Org {
    name: string;
    default_locale: string;
    default_currency: string;
    timezone: string;
    company_oib: string | null;
    /** Dashboard "Revenue vs. target" / "Target by channel" inputs — minor units, null until a settings.manage member sets them. */
    annual_revenue_target: number | null;
    channel_revenue_targets: Record<string, number> | null;
    /** Dashboard "Runway" inputs — minor units, null until set. */
    cash_on_hand: number | null;
    cash_on_hand_as_of: string | null;
}

export interface SharedProps {
    auth: Auth;
    tenant: TenantSummary | null;
    tenants: TenantMembership[];
    /** Module keys the active tenant's plan includes (see App\Enums\Module). */
    modules: string[];
    flash: Flash;
    locale: string;
    /** config('app.supported_locales') — drives LanguageSwitcher's options. */
    locales: string[];
    /** Display label per locale code, e.g. { hr: 'Hrvatski', en: 'English' }. */
    localeLabels: Record<string, string>;
    /**
     * The merged file+DB-override UI-copy catalog for the current locale
     * (App\Services\Localization\TranslationService::all()), keyed by
     * English source string — see useTranslations().
     */
    translations: Record<string, string>;
    /** Null when there's no active tenant (e.g. a guest on the login page). */
    org: Org | null;
    /**
     * Set only while a platform admin is "logged in as" the current user —
     * see App\Actions\Auth\StartImpersonationAction. Drives ImpersonationBanner.
     */
    impersonating: { impersonator_name: string; target_name: string } | null;
    errors: Record<string, string>;
    /**
     * Recently-visited nav-item keys, newest first — `Inertia::optional`, so it
     * is only present after a partial reload asks for it (Manage Shortcuts
     * opening). Undefined otherwise, never an empty array standing in for
     * "not fetched yet".
     */
    recentNavVisits?: string[];
    [key: string]: unknown;
}

/** Laravel's paginator envelope, as returned by the existing Query objects. */
export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}
