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
    | 'team';

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
}

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
}

/** In the hub's order, which is OnboardingStatus::STEPS's order. */
export const ONBOARDING_STEPS: OnboardingStepMeta[] = [
    {
        key: 'company_profile',
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
        title: 'Pricing tiers',
        why: 'Price books such as Wholesale or Retail, each with its own rebate. Customers on a tier get its prices automatically.',
        href: '/customers/pricing-tiers',
        minutes: 5,
        module: 'customers',
    },
    {
        key: 'customers',
        title: 'Customers',
        why: 'Add the businesses you sell to and connect each one to a pricing tier or its own rebate.',
        href: '/customers',
        minutes: 10,
        module: 'customers',
    },
    {
        key: 'team',
        title: 'Invite your team',
        why: 'Give colleagues their own login with only the access their role needs.',
        href: '/settings/team',
        minutes: 3,
        module: 'settings',
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

/** The steps that apply to this tenant's plan, in order. */
export function onboardingStepsFor(hasModule: (module: string) => boolean): OnboardingStepMeta[] {
    return ONBOARDING_STEPS.filter((step) => hasModule(step.module));
}
