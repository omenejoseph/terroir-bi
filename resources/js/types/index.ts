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

export interface SharedProps {
    auth: Auth;
    tenant: TenantSummary | null;
    tenants: TenantMembership[];
    flash: Flash;
    locale: string;
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
