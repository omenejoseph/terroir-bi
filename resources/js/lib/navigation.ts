/**
 * Sidebar navigation for the Inertia app.
 *
 * Only modules that have actually been ported appear here. As each module gains
 * a Web controller + Inertia page, move it out of PENDING_MODULES and into
 * NAV_ITEMS — that keeps the sidebar free of dead links while the port is in
 * progress, and makes the remaining work visible in one place.
 *
 * `capability` mirrors the `can:*` middleware on the matching route. The server
 * is still the authority; hiding an item is presentation only.
 */

export interface NavItem {
    label: string;
    href: string;
    /** Capability required to see the item; omit for "any active member". */
    capability?: string;
}

export const NAV_ITEMS: NavItem[] = [
    { label: 'Dashboard', href: '/dashboard' },
    { label: 'Inventory', href: '/inventory', capability: 'inventory.view' },
];

/**
 * Still served by the Next.js app in `frontend/`. Ordered roughly by how much
 * each depends on the shared primitives built so far.
 */
export const PENDING_MODULES = [
    'orders',
    'customers',
    'suppliers',
    'costs',
    'inflows',
    'cash-flow',
    'cellar',
    'vineyards',
    'production',
    'work-orders',
    'ai-imports',
    'team',
    'settings',
] as const;

export function navigationFor(can: (capability: string) => boolean): NavItem[] {
    return NAV_ITEMS.filter((item) => item.capability === undefined || can(item.capability));
}
