import {
    Banknote,
    Boxes,
    Building2,
    CalendarDays,
    ChartLine,
    ClipboardList,
    Clock,
    Coins,
    ConciergeBell,
    CookingPot,
    CreditCard,
    Factory,
    FileStack,
    Grape,
    LayoutGrid,
    type LucideIcon,
    MessageCircle,
    Package,
    Pin,
    Receipt,
    Settings,
    ShoppingCart,
    Sprout,
    Store,
    Truck,
    UserRound,
    Users,
    Warehouse,
    Wine,
} from 'lucide-vue-next';

/**
 * Sidebar navigation, mirroring the TERROIR Figma design (node 547:1568,
 * "ExpandedNav"). The design groups items under collapsible category headings
 * rather than presenting one flat list; the order and labels below are taken
 * from that node verbatim.
 *
 * `href: null` marks an item the design specifies but the Inertia app does not
 * serve yet — it renders disabled rather than as a dead link, so the sidebar
 * matches the design while making the remaining work visible. Give it a path as
 * each module is ported.
 *
 * `capability` mirrors the `can:*` middleware on the matching route. The server
 * is the authority; hiding an item is presentation only.
 *
 * Icons: the Figma assets could not be exported (the asset host is blocked by
 * this environment's egress policy), so these are the matching Lucide glyphs —
 * the same icon set the outgoing React app used. See docs/design/README.md.
 *
 * `key` is the stable identifier Manage Shortcuts pins against — it must match
 * `App\Support\NavCatalog` on the backend (kebab-case of the label, checked
 * for uniqueness across every category since two categories both happening to
 * have a "Harvest" would collide).
 */

export interface NavItem {
    key: string;
    label: string;
    /** null = designed but not yet implemented; renders disabled. */
    href: string | null;
    icon: LucideIcon;
    capability?: string;
}

export interface NavCategory {
    label: string;
    /**
     * The glyph the collapsed rail stands in for the whole category, since
     * there is no room for the heading. Read off the rail in the design's
     * screen frames (e.g. `230:2412`), which are all drawn collapsed.
     */
    icon: LucideIcon;
    items: NavItem[];
}

export const NAV_CATEGORIES: NavCategory[] = [
    {
        label: 'Overview',
        icon: LayoutGrid,
        items: [
            { key: 'dashboard', label: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
            { key: 'cash-system', label: 'Cash System', href: null, icon: Coins, capability: 'finance.view' },
        ],
    },
    {
        label: 'Sales',
        icon: ShoppingCart,
        items: [
            { key: 'orders', label: 'Orders', href: '/orders', icon: ShoppingCart, capability: 'orders.view' },
            { key: 'customers', label: 'Customers', href: '/customers', icon: Users, capability: 'customers.view' },
            // Task planning is open to any member (routes/api.php and routes/web.php
            // both leave it ungated), so this entry carries no capability — hiding it
            // from someone the route admits would be the sidebar lying.
            { key: 'work-orders', label: 'Work Orders', href: '/work-orders', icon: ClipboardList },
            { key: 'wine-club', label: 'Wine Club', href: null, icon: Wine, capability: 'customers.view' },
            { key: 'agencies', label: 'Agencies', href: null, icon: Building2, capability: 'customers.view' },
            { key: 'pipeline', label: 'Pipeline', href: null, icon: ChartLine, capability: 'customers.view' },
        ],
    },
    {
        label: 'Hospitality',
        icon: ConciergeBell,
        items: [
            { key: 'accommodation', label: 'Accommodation', href: null, icon: Store },
            { key: 'kitchen', label: 'Kitchen', href: null, icon: CookingPot },
            { key: 'hospitality', label: 'Hospitality', href: null, icon: Wine },
        ],
    },
    {
        label: 'Production',
        icon: Factory,
        items: [
            { key: 'cellar', label: 'Cellar', href: null, icon: Warehouse, capability: 'cellar.view' },
            { key: 'harvest', label: 'Harvest', href: null, icon: Grape, capability: 'vineyards.view' },
            { key: 'production-plan', label: 'Production Plan', href: null, icon: Sprout, capability: 'production.view' },
        ],
    },
    {
        label: 'Supply',
        icon: Boxes,
        items: [
            { key: 'inventory', label: 'Inventory', href: '/inventory', icon: Package, capability: 'inventory.view' },
            { key: 'purchase-orders', label: 'Purchase Orders', href: null, icon: FileStack, capability: 'suppliers.view' },
            { key: 'suppliers', label: 'Suppliers', href: null, icon: Truck, capability: 'suppliers.view' },
        ],
    },
    {
        label: 'Finance',
        icon: Banknote,
        items: [
            { key: 'costs', label: 'Costs', href: null, icon: Receipt, capability: 'finance.view' },
            { key: 'inflow', label: 'Inflow', href: null, icon: CreditCard, capability: 'finance.view' },
            { key: 'cash-flow', label: 'Cash Flow', href: null, icon: ChartLine, capability: 'financials.view' },
        ],
    },
    {
        label: 'Team',
        icon: Users,
        items: [
            { key: 'employees', label: 'Employees', href: null, icon: Users, capability: 'members.view' },
            { key: 'schedules', label: 'Schedules', href: null, icon: CalendarDays },
            { key: 'my-team', label: 'My Team', href: null, icon: UserRound },
            { key: 'surveys', label: 'Surveys', href: null, icon: ClipboardList },
            { key: 'my-hours', label: 'My Hours', href: null, icon: Clock },
        ],
    },
    {
        label: 'System',
        icon: Settings,
        items: [
            { key: 'settings', label: 'Settings', href: null, icon: Settings, capability: 'settings.manage' },
            { key: 'whatsapp-bot', label: 'WhatsApp Bot', href: null, icon: MessageCircle, capability: 'settings.manage' },
        ],
    },
];

export const SHORTCUTS_ICON = Pin;

/** Every nav item, keyed for Manage Shortcuts' pin/unpin and the visit tracker's lookup. */
const NAV_ITEMS_BY_KEY: Record<string, NavItem> = Object.fromEntries(
    NAV_CATEGORIES.flatMap((category) => category.items).map((item) => [item.key, item]),
);

/** Drop items the member has no capability for; keep categories that still have items. */
export function navigationFor(can: (capability: string) => boolean): NavCategory[] {
    return NAV_CATEGORIES.map((category) => ({
        ...category,
        items: category.items.filter((item) => item.capability === undefined || can(item.capability)),
    })).filter((category) => category.items.length > 0);
}

/**
 * The design's "Shortcuts" section (Figma `547:1610`) — a user-pinned list
 * sitting above the categories. `pinnedKeys` is the member's own ordered
 * pins from `auth.shortcuts`; unknown keys (a stale pin from a removed nav
 * item) and ones the member has lost the capability for are silently
 * dropped rather than shown broken.
 */
export function shortcutsFor(can: (capability: string) => boolean, pinnedKeys: string[]): NavItem[] {
    return pinnedKeys
        .map((key) => NAV_ITEMS_BY_KEY[key])
        .filter((item): item is NavItem => item !== undefined && (item.capability === undefined || can(item.capability)));
}

/** Every pinnable item the member currently has capability for, for the Manage Shortcuts dialog. */
export function pinnableItemsFor(can: (capability: string) => boolean): NavItem[] {
    return NAV_CATEGORIES.flatMap((category) => category.items).filter(
        (item) => item.capability === undefined || can(item.capability),
    );
}

export function navItemByKey(key: string): NavItem | undefined {
    return NAV_ITEMS_BY_KEY[key];
}
