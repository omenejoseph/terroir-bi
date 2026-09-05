import {
    Bot,
    Building2,
    CreditCard,
    FlaskConical,
    KeyRound,
    LayoutGrid,
    Languages,
    Megaphone,
    Receipt,
    ShieldCheck,
    Users,
} from 'lucide-vue-next';

import type { NavCategory } from '@/lib/navigation';

/**
 * The platform-admin ("/admin") sidebar's static nav — port of the Filament
 * panel's nav groups (app/Filament/Resources/**, app/Filament/Pages/**),
 * reusing the same NavCategory/NavItem shape as the tenant app's
 * lib/navigation.ts so AdminSidebar.vue can render rows with the existing
 * NavRow component.
 *
 * Unlike the tenant nav, nothing here is capability/module-gated — access to
 * `/admin` is binary (is_platform_admin), enforced by the platform.admin
 * middleware group server-side. `href: null` still means "designed but not
 * built yet" (renders disabled via NavRow), same convention as
 * lib/navigation.ts — filled in as each tier lands.
 */

/** Matches routes/admin.php's prefix. */
export const ADMIN_BASE = '/admin';

export const ADMIN_NAV_CATEGORIES: NavCategory[] = [
    {
        label: 'Overview',
        icon: LayoutGrid,
        items: [{ key: 'admin-dashboard', label: 'Dashboard', href: ADMIN_BASE, icon: LayoutGrid }],
    },
    {
        label: 'Billing',
        icon: CreditCard,
        items: [
            { key: 'admin-tenants', label: 'Tenants', href: `${ADMIN_BASE}/tenants`, icon: Building2 },
            { key: 'admin-plans', label: 'Plans', href: `${ADMIN_BASE}/plans`, icon: CreditCard },
            { key: 'admin-stripe-settings', label: 'Stripe Settings', href: `${ADMIN_BASE}/stripe-settings`, icon: Receipt },
        ],
    },
    {
        label: 'Access',
        icon: ShieldCheck,
        items: [
            {
                key: 'admin-platform-admins',
                label: 'Platform Admins',
                href: `${ADMIN_BASE}/platform-admins`,
                icon: ShieldCheck,
            },
            { key: 'admin-users', label: 'Users', href: `${ADMIN_BASE}/users`, icon: Users },
        ],
    },
    {
        label: 'Quality',
        icon: FlaskConical,
        items: [
            { key: 'admin-bdd-scenarios', label: 'BDD Scenarios', href: `${ADMIN_BASE}/bdd-scenarios`, icon: FlaskConical },
            { key: 'admin-bdd-access', label: 'BDD Access', href: `${ADMIN_BASE}/bdd-access`, icon: KeyRound },
        ],
    },
    {
        label: 'Localization',
        icon: Languages,
        items: [
            {
                key: 'admin-translation-overrides',
                label: 'Translation Overrides',
                href: `${ADMIN_BASE}/translation-overrides`,
                icon: Languages,
            },
        ],
    },
    {
        label: 'AI',
        icon: Bot,
        items: [
            { key: 'admin-ai-settings', label: 'AI Settings', href: `${ADMIN_BASE}/ai-settings`, icon: Bot },
            { key: 'admin-ai-spend', label: 'AI Spend', href: `${ADMIN_BASE}/ai-spend`, icon: Receipt },
        ],
    },
    {
        label: 'Communications',
        icon: Megaphone,
        items: [{ key: 'admin-broadcast', label: 'Broadcast', href: `${ADMIN_BASE}/broadcast`, icon: Megaphone }],
    },
];
