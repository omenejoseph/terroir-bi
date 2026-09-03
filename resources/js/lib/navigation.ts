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
 * `capability` mirrors the `can:*` middleware on the matching route, and
 * `module` mirrors an `App\Enums\Module` key (the billable feature area the
 * item belongs to, gated server-side by `EnforceModuleAccess`). The server is
 * the authority for both; hiding an item is presentation only.
 *
 * Icons: the Figma assets could not be exported (the asset host is blocked by
 * this environment's egress policy), so these are the matching Lucide glyphs —
 * the same icon set the outgoing React app used. See docs/design/README.md.
 */

export interface NavItem {
    label: string;
    /** null = designed but not yet implemented; renders disabled. */
    href: string | null;
    icon: LucideIcon;
    capability?: string;
    /** Matches an `App\Enums\Module` key; unset when no module owns the item yet. */
    module?: string;
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
            { label: 'Dashboard', href: '/dashboard', icon: LayoutGrid, module: 'dashboard' },
            { label: 'Cash System', href: null, icon: Coins, capability: 'finance.view', module: 'cash_flow' },
        ],
    },
    {
        label: 'Sales',
        icon: ShoppingCart,
        items: [
            { label: 'Orders', href: '/orders', icon: ShoppingCart, capability: 'orders.view', module: 'orders' },
            { label: 'Customers', href: '/customers', icon: Users, capability: 'customers.view', module: 'customers' },
            // Task planning is open to any member (routes/api.php and routes/web.php
            // both leave it ungated), so this entry carries no capability — hiding it
            // from someone the route admits would be the sidebar lying.
            { label: 'Work Orders', href: '/work-orders', icon: ClipboardList, module: 'work_orders' },
            // These three don't have a real App\Enums\Module case yet — the
            // module keys below are forward-looking, not ones any plan can
            // include today. That's deliberate: the item stays hidden (no
            // tenant's `modules` will ever contain 'wine_club' etc. until the
            // enum, ModuleRegistry and a route exist) without a code change
            // here — add the real module server-side and it starts appearing
            // for any plan that includes it.
            { label: 'Wine Club', href: null, icon: Wine, capability: 'customers.view', module: 'wine_club' },
            { label: 'Agencies', href: null, icon: Building2, capability: 'customers.view', module: 'agencies' },
            { label: 'Pipeline', href: null, icon: ChartLine, capability: 'customers.view', module: 'pipeline' },
        ],
    },
    {
        label: 'Hospitality',
        icon: ConciergeBell,
        // No App\Enums\Module case exists for hospitality yet (it's deferred
        // product-side) — same forward-looking-module pattern as Wine Club
        // above, so this category stays hidden until one does.
        items: [
            { label: 'Accommodation', href: null, icon: Store, module: 'hospitality' },
            { label: 'Kitchen', href: null, icon: CookingPot, module: 'hospitality' },
            { label: 'Hospitality', href: null, icon: Wine, module: 'hospitality' },
        ],
    },
    {
        label: 'Production',
        icon: Factory,
        items: [
            { label: 'Cellar', href: null, icon: Warehouse, capability: 'cellar.view', module: 'cellar' },
            { label: 'Harvest', href: null, icon: Grape, capability: 'vineyards.view', module: 'vineyards' },
            { label: 'Production Plan', href: null, icon: Sprout, capability: 'production.view', module: 'production' },
        ],
    },
    {
        label: 'Supply',
        icon: Boxes,
        items: [
            { label: 'Inventory', href: '/inventory', icon: Package, capability: 'inventory.view', module: 'inventory' },
            { label: 'Purchase Orders', href: null, icon: FileStack, capability: 'suppliers.view', module: 'suppliers' },
            { label: 'Suppliers', href: null, icon: Truck, capability: 'suppliers.view', module: 'suppliers' },
        ],
    },
    {
        label: 'Finance',
        icon: Banknote,
        items: [
            { label: 'Costs', href: null, icon: Receipt, capability: 'finance.view', module: 'costs' },
            { label: 'Inflow', href: null, icon: CreditCard, capability: 'finance.view', module: 'inflows' },
            { label: 'Cash Flow', href: null, icon: ChartLine, capability: 'financials.view', module: 'cash_flow' },
        ],
    },
    {
        label: 'Team',
        icon: Users,
        items: [
            { label: 'Employees', href: null, icon: Users, capability: 'members.view', module: 'team' },
            { label: 'Schedules', href: null, icon: CalendarDays, module: 'team' },
            { label: 'My Team', href: null, icon: UserRound, module: 'team' },
            { label: 'Surveys', href: null, icon: ClipboardList, module: 'team' },
            { label: 'My Hours', href: null, icon: Clock, module: 'team' },
        ],
    },
    {
        label: 'System',
        icon: Settings,
        items: [
            { label: 'Settings', href: null, icon: Settings, capability: 'settings.manage', module: 'settings' },
            { label: 'WhatsApp Bot', href: null, icon: MessageCircle, capability: 'settings.manage', module: 'settings' },
        ],
    },
];

/**
 * The design's "Shortcuts" section — a user-pinned list sitting above the
 * categories. Pinning is not implemented yet, so this renders the design's
 * default set.
 */
export const SHORTCUTS_ICON = Pin;

export const SHORTCUTS: NavItem[] = [
    { label: 'Cash System', href: null, icon: Coins, capability: 'finance.view', module: 'cash_flow' },
    { label: 'Orders', href: '/orders', icon: ShoppingCart, capability: 'orders.view', module: 'orders' },
    { label: 'Harvest', href: null, icon: Grape, capability: 'vineyards.view', module: 'vineyards' },
    { label: 'Inventory', href: '/inventory', icon: Boxes, capability: 'inventory.view', module: 'inventory' },
];

/**
 * Drop items the member has no capability for, or that aren't in the tenant's
 * plan; keep categories that still have items.
 */
export function navigationFor(
    can: (capability: string) => boolean,
    hasModule: (module: string) => boolean,
): NavCategory[] {
    return NAV_CATEGORIES.map((category) => ({
        ...category,
        items: category.items.filter(
            (item) =>
                (item.capability === undefined || can(item.capability)) &&
                (item.module === undefined || hasModule(item.module)),
        ),
    })).filter((category) => category.items.length > 0);
}

export function shortcutsFor(can: (capability: string) => boolean, hasModule: (module: string) => boolean): NavItem[] {
    return SHORTCUTS.filter(
        (item) =>
            (item.capability === undefined || can(item.capability)) &&
            (item.module === undefined || hasModule(item.module)),
    );
}
