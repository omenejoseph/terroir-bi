/**
 * The new-account setup checklist's copy and links (see
 * docs/21-onboarding-plan.md). Where each step *stands* comes from the server
 * (App\Services\Onboarding\OnboardingStatus, keyed by the same `key`); what it
 * is called, why it matters and where the work happens lives here.
 *
 * Every step links to the existing screen that does the work, with
 * `?setup=<key>` appended so that screen shows SetupBanner — a "Back to
 * setup" / "Next step" strip — on top of its normal content.
 */

export type OnboardingStepKey =
    | 'company_profile'
    | 'product_categories'
    | 'finished_products'
    | 'opening_stock'
    | 'pricing_tiers'
    | 'customers'
    | 'team'
    | 'first_order'
    | 'product_costs'
    | 'special_prices'
    | 'customer_categories'
    | 'order_portal'
    | 'min_stock'
    | 'materials'
    | 'recipes'
    | 'photos'
    | 'revenue_targets'
    | 'cellar_vessels'
    | 'cellar_lots'
    | 'cellar_supplies'
    | 'work_orders';

export type OnboardingStepStatus = 'not_started' | 'in_progress' | 'done' | 'skipped';

/** One step as App\Services\Onboarding\OnboardingStep::toArray() sends it. */
export interface OnboardingStepState {
    key: OnboardingStepKey;
    status: OnboardingStepStatus;
    progress: { done: number; total: number } | null;
    count: number | null;
    /** `message` is a translation key taking :count. */
    issues: { message: string; count: number }[];
    skippable: boolean;
    markable: boolean;
    /** OnboardingStatus::sectionOf(); the dashboard reminder only counts 'go_live'. */
    section: OnboardingSection;
}

export type OnboardingSection = 'go_live' | 'sell_better' | 'deepen';

/** The dashboard reminder's numbers (OnboardingStatus::summary()). */
export interface OnboardingSummary {
    done: number;
    total: number;
    complete: boolean;
    dismissed: boolean;
    /** The welcome questions were answered or skipped. */
    welcomed: boolean;
}

export interface OnboardingStepMeta {
    key: OnboardingStepKey;
    title: string;
    /** One line on why the step matters — shown on the hub card and the banner. */
    why: string;
    href: string;
    /** Rough time for a first pass, shown on the hub card. */
    minutes: number;
    /** Mirrors OnboardingStatus::STEPS — a step outside the tenant's plan is never shown. */
    module: string;
    /** The help article (resources/help/{locale}/{id}.md) that walks through it, if there is one. */
    help?: string;
}

/** In the hub's order, which is OnboardingStatus::STEPS's order. */
export const ONBOARDING_STEPS: OnboardingStepMeta[] = [
    {
        key: 'company_profile',
        help: 'company-profile',
        title: 'Company profile',
        why: 'Your address, tax ID, contact and bank details appear on every order PDF.',
        href: '/settings',
        minutes: 3,
        module: 'settings',
    },
    {
        key: 'product_categories',
        title: 'Product categories',
        why: 'Group your wines (e.g. Red, White, Sparkling) so products, reports and the order form stay organised.',
        href: '/inventory/categories',
        minutes: 5,
        module: 'inventory',
    },
    {
        key: 'finished_products',
        title: 'Finished products',
        why: 'Add the wines you sell with a list price, vintage, size and sales unit. Bulk import works for long lists.',
        // Setup's own quick grid + CSV import, rather than the full Inventory page.
        href: '/setup/products',
        minutes: 15,
        module: 'inventory',
    },
    {
        key: 'opening_stock',
        title: 'Opening stock',
        why: 'Count what is in the warehouse today, so orders deduct from real numbers instead of going negative.',
        href: '/inventory-check',
        minutes: 10,
        module: 'inventory',
    },
    {
        key: 'pricing_tiers',
        help: 'pricing-tiers',
        title: 'Pricing tiers',
        why: 'Price books such as Wholesale or Retail, each with its own rebate. Customers on a tier get its prices automatically.',
        href: '/customers/pricing-tiers',
        minutes: 5,
        module: 'customers',
    },
    {
        key: 'customers',
        help: 'add-customers',
        title: 'Customers',
        why: 'Add the businesses you sell to and connect each one to a pricing tier or its own rebate.',
        href: '/customers',
        minutes: 10,
        module: 'customers',
    },
    {
        key: 'team',
        help: 'invite-team',
        title: 'Invite your team',
        why: 'Give colleagues their own login with only the access their role needs.',
        href: '/settings/team',
        minutes: 3,
        module: 'settings',
    },
    // ── Sell better ────────────────────────────────────────────────
    {
        key: 'first_order',
        help: 'take-an-order',
        title: 'Place your first order',
        why: 'Run one order end to end and check its PDF. If it was only a test, delete it afterwards.',
        href: '/orders',
        minutes: 5,
        module: 'orders',
    },
    {
        key: 'product_costs',
        title: 'Product costs',
        why: 'A cost per bottle (or a recipe it is worked out from) turns revenue into real margins.',
        href: '/inventory?missing_cost=1',
        minutes: 15,
        module: 'inventory',
    },
    {
        key: 'special_prices',
        help: 'how-prices-work',
        title: 'Special prices',
        why: 'Set a fixed price per tier on a product\'s Pricing tab, or agree a price with one customer — those override the rebate.',
        href: '/inventory',
        minutes: 10,
        module: 'customers',
    },
    {
        key: 'customer_categories',
        title: 'Customer categories',
        why: 'Restaurants, shops, distributors… Categories split analytics and the reorder radar.',
        href: '/customers/categories',
        minutes: 5,
        module: 'customers',
    },
    {
        key: 'order_portal',
        help: 'customer-order-links',
        title: 'Customer order links',
        why: 'Give a customer their own link to order from your catalogue at their prices, without logging in.',
        href: '/customers',
        minutes: 5,
        module: 'customers',
    },
    {
        key: 'min_stock',
        title: 'Reorder points',
        why: 'A minimum stock per wine raises a low-stock alert before you sell out.',
        href: '/inventory',
        minutes: 10,
        module: 'inventory',
    },
    {
        key: 'materials',
        title: 'Materials and bulk wine',
        why: 'Glass, corks, labels, cartons and bulk wine — needed for recipes, production and reordering.',
        href: '/inventory',
        minutes: 15,
        module: 'inventory',
    },
    // ── Deepen ─────────────────────────────────────────────────────
    {
        key: 'recipes',
        title: 'Product recipes',
        why: 'What one bottle is made of — wine, glass, cork, label. Production then deducts the inputs and works out the cost.',
        href: '/inventory',
        minutes: 20,
        module: 'inventory',
    },
    {
        key: 'photos',
        title: 'Product photos',
        why: 'A photo per wine, shown on product pages and on your customers\' order page.',
        href: '/inventory',
        minutes: 15,
        module: 'inventory',
    },
    {
        key: 'revenue_targets',
        title: 'Revenue targets',
        why: 'An annual target (and per channel, if you like) for the dashboard to measure revenue against.',
        href: '/settings?tab=revenue-target',
        minutes: 3,
        module: 'settings',
    },
    {
        key: 'cellar_vessels',
        title: 'Cellar vessels',
        why: 'Your tanks, barrels and other vessels, arranged by room — the map the cellar works from.',
        href: '/cellar',
        minutes: 15,
        module: 'cellar',
    },
    {
        key: 'cellar_lots',
        title: 'Wine in the cellar',
        why: 'The lots currently in tank or barrel, with their volume, so additions, analyses and bottling have something to record against.',
        href: '/cellar/lots',
        minutes: 15,
        module: 'cellar',
    },
    {
        key: 'cellar_supplies',
        title: 'Cellar supplies',
        why: 'Yeasts, SO₂, fining agents and other enological products, with stock — used by additions and protocols.',
        href: '/cellar/products',
        minutes: 10,
        module: 'cellar',
    },
    {
        key: 'work_orders',
        title: 'Work orders',
        why: 'Plan the team\'s tasks on a board — create the first one and assign it.',
        href: '/work-orders',
        minutes: 5,
        module: 'work_orders',
    },
];

const BY_KEY = Object.fromEntries(ONBOARDING_STEPS.map((step) => [step.key, step])) as Record<OnboardingStepKey, OnboardingStepMeta>;

export function onboardingStep(key: string): OnboardingStepMeta | undefined {
    return (BY_KEY as Record<string, OnboardingStepMeta | undefined>)[key];
}

/** The step's screen, opened in setup mode. */
export function setupHref(step: OnboardingStepMeta): string {
    return `${step.href}${step.href.includes('?') ? '&' : '?'}setup=${step.key}`;
}

/** Mirrors OnboardingStatus::SELL_BETTER; steps in neither list are "Go live". */
const SELL_BETTER: OnboardingStepKey[] = ['first_order', 'product_costs', 'special_prices', 'customer_categories', 'order_portal', 'min_stock', 'materials'];

/** Mirrors OnboardingStatus::DEEPEN. */
const DEEPEN: OnboardingStepKey[] = ['recipes', 'photos', 'revenue_targets', 'cellar_vessels', 'cellar_lots', 'cellar_supplies', 'work_orders'];

export function sectionOf(key: OnboardingStepKey): OnboardingSection {
    if (SELL_BETTER.includes(key)) return 'sell_better';

    return DEEPEN.includes(key) ? 'deepen' : 'go_live';
}

/** The steps that apply to this tenant's plan, in order. */
export function onboardingStepsFor(hasModule: (module: string) => boolean): OnboardingStepMeta[] {
    return ONBOARDING_STEPS.filter((step) => hasModule(step.module));
}
