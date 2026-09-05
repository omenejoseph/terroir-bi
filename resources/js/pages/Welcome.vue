<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Banknote,
    Boxes,
    CircleCheck,
    Container,
    Factory,
    Grape,
    LayoutGrid,
    Package,
    ShoppingCart,
    Sprout,
    Users,
    Warehouse,
} from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import Button from '@/components/ui/Button.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * The public marketing home page (`WelcomeController`), reachable at `/`
 * without a session. It reuses the same design tokens and primitives as the
 * authenticated app (AppLogo, Button, StackedBar, StatCard) rather than
 * inventing a parallel visual language for one page, and the module list
 * below is read off `lib/navigation.ts`'s own categories, not written fresh.
 *
 * Hospitality is deliberately absent: it has no `App\Enums\Module` case yet
 * (see navigation.ts), so it isn't marketed as a shipped module.
 */
const { t } = useTranslations();

const anchorBtn =
    'inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-input bg-transparent px-6 text-base font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';
const anchorBtnPrimary =
    'inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary px-6 text-base font-medium whitespace-nowrap text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';

const channelMix = [
    { label: t('Wholesale'), pct: 41 },
    { label: t('Retail'), pct: 24 },
    { label: t('Agency'), pct: 16 },
    { label: t('Ship-Shop'), pct: 12 },
    { label: t('Other'), pct: 7 },
];

const ratios = [
    { label: t('DTC Revenue'), value: '24%' },
    { label: t('Operating Margin'), value: '18%' },
    { label: t('COGS'), value: '34%' },
    { label: t('Inventory Turnover'), value: '3.1×' },
];

const workflow = [
    { icon: Grape, group: t('PRODUCTION'), title: t('Harvest'), desc: t('Blocks, vintages, and yields, logged as the fruit comes in.') },
    { icon: Warehouse, group: t('PRODUCTION'), title: t('Cellar'), desc: t('Fermentation, aging, and bottling, tracked vessel by vessel.') },
    { icon: Sprout, group: t('PRODUCTION'), title: t('Production Plan'), desc: t('Cellar lots turn into finished goods on a schedule, not a guess.') },
    { icon: Package, group: t('SUPPLY'), title: t('Inventory'), desc: t('Finished stock and incoming purchase orders share one count.') },
    { icon: ShoppingCart, group: t('SALES'), title: t('Orders'), desc: t('Wholesale, retail, agency, and ship-shop, each on its own terms.') },
    { icon: Banknote, group: t('FINANCE'), title: t('Cash Flow'), desc: t('Costs and inflow roll up the moment an invoice moves.') },
];

const modules = [
    {
        icon: LayoutGrid,
        title: t('Overview'),
        desc: t('Net cash flow, low stock, and the ratios that say whether the vintage is actually paying for itself.'),
        items: [t('Dashboard'), t('Cash System')],
    },
    {
        icon: ShoppingCart,
        title: t('Sales'),
        desc: t('Orders and customers organized by real channel, plus the wine club and agency relationships behind them.'),
        items: [t('Orders'), t('Customers'), t('Work Orders'), t('Wine Club'), t('Agencies'), t('Pipeline')],
    },
    {
        icon: Factory,
        title: t('Production'),
        desc: t('From harvest to finished lot, every block and vessel tracked back to the fruit it came from.'),
        items: [t('Cellar'), t('Harvest'), t('Production Plan')],
    },
    {
        icon: Boxes,
        title: t('Supply'),
        desc: t('Stock, purchase orders, and the suppliers behind them, so a shortage never shows up as an empty shelf first.'),
        items: [t('Inventory'), t('Purchase Orders'), t('Suppliers')],
    },
    {
        icon: Banknote,
        title: t('Finance'),
        desc: t('Costs, inflow, and cash flow, built from the orders and invoices already on the books.'),
        items: [t('Costs'), t('Inflow'), t('Cash Flow')],
    },
    {
        icon: Users,
        title: t('Team'),
        desc: t('Schedules and hours, because employee cost is a line on the dashboard, not an afterthought at year end.'),
        items: [t('Employees'), t('Schedules'), t('Surveys'), t('My Hours')],
    },
];

/** Mirrors StackedBar's own fixed tones: the top channel is emphasised, the rest recede. */
const CHANNEL_TONES = ['bg-foreground', 'bg-muted-foreground/35', 'bg-muted-foreground/20', 'bg-muted-foreground/20', 'bg-muted-foreground/20'];

const channels = [
    { name: t('Wholesale'), pct: '41%', desc: t('Bulk orders to distributors, priced on standing terms.') },
    { name: t('Retail'), pct: '24%', desc: t('Direct sales at the estate, list price, settled on the spot.') },
    { name: t('Agency'), pct: '16%', desc: t('Sold through an agency on commission, paid once, on delivery.') },
    { name: t('Ship-Shop'), pct: '12%', desc: t('Consignment stock placed with a partner shop, reconciled as it sells through.') },
    { name: t('Other'), pct: '7%', desc: t('Everything that does not fit the four above, still tracked the same way.') },
];
</script>

<template>
    <Head>
        <title>{{ t('Terroir Business Intelligence for wine estates') }}</title>
        <meta
            name="description"
            :content="t('One ledger for the vineyard, the cellar, and the balance sheet.')"
        />
    </Head>

    <div class="min-h-screen bg-background text-foreground">
        <header class="sticky top-0 z-40 border-b border-border bg-background/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-6 py-3">
                <a href="#top" class="flex items-center gap-2.5">
                    <AppLogo />
                    <span class="min-w-0">
                        <p class="text-13 font-semibold text-foreground">{{ t('Terroir') }}</p>
                        <p class="text-2xs text-muted-foreground">{{ t('Business Intelligence') }}</p>
                    </span>
                </a>
                <nav class="hidden items-center gap-7 text-sm text-muted-foreground sm:flex">
                    <a href="#platform" class="hover:text-foreground">{{ t('Platform') }}</a>
                    <a href="#workflow" class="hover:text-foreground">{{ t('Workflow') }}</a>
                    <a href="#channels" class="hover:text-foreground">{{ t('Channels') }}</a>
                    <a href="#board" class="hover:text-foreground">{{ t('Work Orders') }}</a>
                </nav>
                <div class="flex items-center gap-3">
                    <Button href="/login" variant="ghost" size="md">{{ t('Log in') }}</Button>
                    <Button href="/login" variant="primary" size="md">{{ t('Request a demo') }}</Button>
                </div>
            </div>
        </header>

        <main>
            <section id="top" class="mx-auto grid max-w-6xl gap-12 px-6 py-16 lg:grid-cols-[1.05fr_.95fr] lg:items-start lg:gap-14 lg:py-20">
                <div>
                    <p class="mb-4 inline-flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        <span class="size-1.5 bg-primary" aria-hidden="true" />
                        {{ t('Vineyard to ledger') }}
                    </p>
                    <h1 class="text-4xl leading-[1.08] font-bold tracking-tight text-balance sm:text-5xl">
                        {{ t('One ledger for the vineyard, the cellar, and the balance sheet.') }}
                    </h1>
                    <p class="mt-5 max-w-[42ch] text-lg text-muted-foreground">
                        {{
                            t(
                                'Terroir Business Intelligence is the operating system for a wine estate: harvest and cellar on one side, orders and cash flow on the other, reconciled automatically instead of by hand at month end.',
                            )
                        }}
                    </p>
                    <div class="mt-7 flex flex-wrap gap-3">
                        <a :class="anchorBtnPrimary" href="mailto:hello@terroirbi.com">{{ t('Request a demo') }}</a>
                        <a :class="anchorBtn" href="#workflow">{{ t('See the workflow') }}</a>
                    </div>
                    <p class="mt-6 text-sm text-muted-foreground">
                        {{ t('Multi-tenant from day one.') }}
                        <span class="font-medium text-foreground">{{ t('Employee Cost') }}</span>
                        {{ t('and') }}
                        <span class="font-medium text-foreground">{{ t('COGS') }}</span>
                        {{ t('sit on the same dashboard as the harvest.') }}
                    </p>
                </div>

                <div class="rounded-lg border border-border bg-card">
                    <div class="flex items-center justify-between gap-4 border-b border-border px-5 py-4">
                        <p class="text-sm font-semibold">{{ t('Dashboard') }}</p>
                        <span class="inline-flex items-center rounded-lg bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">
                            {{ t('This estate') }}
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-px border-b border-border bg-border">
                        <StatCard :label="t('Net Cash Flow')" value="+€18,240" :hint="t('last 30 days')" class="rounded-none border-0" />
                        <StatCard :label="t('Low Stock')" value="6" :hint="t('SKUs below par')" alert class="rounded-none border-0" />
                    </div>
                    <div class="p-5">
                        <p class="text-13 font-semibold">{{ t('Revenue by channel') }}</p>
                        <div class="mt-3">
                            <StackedBar
                                :segments="
                                    channelMix.map((c) => ({ label: c.label, value: c.pct, caption: `${c.pct}%` }))
                                "
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-4 gap-px border-t border-border bg-border">
                        <div v-for="r in ratios" :key="r.label" class="bg-card p-3">
                            <p class="text-2xs text-muted-foreground">{{ r.label }}</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">{{ r.value }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="workflow" class="border-y border-sidebar-border bg-sidebar">
                <div class="mx-auto max-w-6xl px-6 py-16">
                    <div class="mx-auto mb-10 max-w-xl text-center">
                        <p class="mb-3 inline-flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            <span class="size-1.5 bg-primary" aria-hidden="true" />
                            {{ t('The estate, in order') }}
                        </p>
                        <h2 class="text-2xl font-bold tracking-tight text-balance sm:text-3xl">
                            {{ t('Six modules, in the order the wine actually moves.') }}
                        </h2>
                        <p class="mt-3 text-muted-foreground">
                            {{
                                t(
                                    'Data starts at the vine and moves forward on its own, module to module, without anyone re-entering it downstream.',
                                )
                            }}
                        </p>
                    </div>
                    <ol class="grid grid-cols-2 gap-px border border-border bg-border sm:grid-cols-3 lg:grid-cols-6">
                        <li v-for="(step, i) in workflow" :key="step.title" class="flex flex-col gap-3 bg-card p-5">
                            <span class="flex size-9 items-center justify-center border border-border">
                                <component :is="step.icon" class="size-4.5" :stroke-width="1.75" aria-hidden="true" />
                            </span>
                            <div>
                                <p class="text-2xs font-semibold text-muted-foreground">{{ String(i + 1).padStart(2, '0') }} · {{ step.group }}</p>
                                <h3 class="mt-0.5 text-sm font-semibold">{{ step.title }}</h3>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ step.desc }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <section id="platform" class="mx-auto max-w-6xl px-6 py-16">
                <div class="mx-auto mb-10 max-w-xl text-center">
                    <p class="mb-3 inline-flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        <span class="size-1.5 bg-primary" aria-hidden="true" />
                        {{ t('The platform') }}
                    </p>
                    <h2 class="text-2xl font-bold tracking-tight text-balance sm:text-3xl">
                        {{ t('Every module reads from the same ledger.') }}
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        {{
                            t(
                                'Nothing here is a separate app bolted on. The cellar, the sales team, and the bookkeeper are all looking at the same numbers.',
                            )
                        }}
                    </p>
                </div>
                <div class="grid grid-cols-1 gap-px border border-border bg-border sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="mod in modules" :key="mod.title" class="bg-card p-6">
                        <span class="mb-4 flex size-9 items-center justify-center bg-secondary">
                            <component :is="mod.icon" class="size-4.5" :stroke-width="1.75" aria-hidden="true" />
                        </span>
                        <h3 class="text-base font-semibold">{{ mod.title }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ mod.desc }}</p>
                        <div class="mt-4 flex flex-wrap gap-1.5">
                            <span
                                v-for="item in mod.items"
                                :key="item"
                                class="border border-border px-2 py-0.5 text-2xs text-muted-foreground"
                            >
                                {{ item }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section id="channels" class="mx-auto max-w-6xl px-6 py-16">
                <div class="grid gap-13 lg:grid-cols-2 lg:items-start">
                    <div>
                        <p class="mb-3 inline-flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            <span class="size-1.5 bg-primary" aria-hidden="true" />
                            {{ t('Every channel, priced its own way') }}
                        </p>
                        <h2 class="text-2xl font-bold tracking-tight text-balance sm:text-3xl">
                            {{ t('Wholesale, retail, agency, and ship-shop are not the same sale.') }}
                        </h2>
                        <p class="mt-4 text-muted-foreground">
                            {{
                                t(
                                    "A case sold wholesale, carried by an agency, or placed on consignment each earns differently and settles on a different schedule. The channel is set once, on the customer, and every order and report downstream already knows it.",
                                )
                            }}
                        </p>
                        <ul class="mt-6 divide-y divide-border border-t border-border">
                            <li v-for="(c, i) in channels" :key="c.name" class="py-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="size-2.5 shrink-0" :class="CHANNEL_TONES[i]" />
                                    <span class="font-semibold">{{ c.name }}</span>
                                    <span class="ml-auto text-sm text-muted-foreground tabular-nums">{{ c.pct }}</span>
                                </div>
                                <p class="mt-1.5 pl-5 text-sm text-muted-foreground">{{ c.desc }}</p>
                            </li>
                        </ul>
                    </div>
                    <div class="rounded-lg border border-border bg-card p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <p class="text-sm font-semibold">{{ t('Customers') }}</p>
                            <span class="border border-border px-2.5 py-0.5 text-xs">{{ t('By channel') }}</span>
                        </div>
                        <p class="mb-4 text-xs text-muted-foreground">
                            {{ t("A customer's channel is set once and drives pricing, terms, and every report below.") }}
                        </p>
                        <ul class="flex flex-col gap-3 text-sm">
                            <li class="flex items-center justify-between">
                                <span class="inline-flex items-center bg-primary px-2.5 py-0.5 text-xs font-medium text-primary-foreground">{{ t('Wholesale') }}</span>
                                <span class="text-muted-foreground">{{ t('Standing terms') }}</span>
                            </li>
                            <li class="flex items-center justify-between">
                                <span class="inline-flex items-center bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">{{ t('Retail') }}</span>
                                <span class="text-muted-foreground">{{ t('On the spot') }}</span>
                            </li>
                            <li class="flex items-center justify-between">
                                <span class="inline-flex items-center border border-input px-2.5 py-0.5 text-xs font-medium">{{ t('Agency') }}</span>
                                <span class="text-muted-foreground">{{ t('Commission, on delivery') }}</span>
                            </li>
                            <li class="flex items-center justify-between">
                                <span class="inline-flex items-center border border-input px-2.5 py-0.5 text-xs font-medium">{{ t('Ship-Shop') }}</span>
                                <span class="text-muted-foreground">{{ t('Consignment') }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section id="board" class="mx-auto max-w-6xl px-6 py-16">
                <div class="grid gap-13 lg:grid-cols-2 lg:items-start">
                    <div>
                        <p class="mb-3 inline-flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            <span class="size-1.5 bg-primary" aria-hidden="true" />
                            {{ t('The one screen that uses color') }}
                        </p>
                        <h2 class="text-2xl font-bold tracking-tight text-balance sm:text-3xl">
                            {{ t('Everywhere else, this page is black and white. So is the product.') }}
                        </h2>
                        <p class="mt-4 text-muted-foreground">
                            {{
                                t(
                                    'Work Orders is the exception, and it earns it: a category tag says what kind of work a card is, a priority tag says how badly it is wanted, both readable without opening the card. An overdue card outlines itself in red, because a date that has passed is the one thing on the board that needs acting on.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-3 rounded-lg border border-border bg-card p-5">
                        <article class="flex flex-col gap-2 border border-destructive p-3">
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 block size-4 shrink-0 border border-input" />
                                <p class="min-w-0 flex-1 text-sm leading-5">{{ t('Rack Tank 04 before topping up') }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 pl-6">
                                <span class="bg-tag-violet px-1.5 py-0.5 text-2xs font-medium tracking-[0.04em] text-tag-violet-foreground uppercase">{{ t('Cellar') }}</span>
                                <span class="bg-tag-orange px-1.5 py-0.5 text-2xs font-medium tracking-[0.04em] text-tag-orange-foreground uppercase">{{ t('High') }}</span>
                            </div>
                            <p class="flex items-center gap-1.5 pl-6 text-xs text-destructive">
                                <Container class="size-3.5 shrink-0" :stroke-width="1.5" aria-hidden="true" />
                                {{ t('12 Aug · Overdue') }}
                            </p>
                        </article>
                        <article class="flex flex-col gap-2 border border-border p-3">
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 block size-4 shrink-0 border border-input" />
                                <p class="min-w-0 flex-1 text-sm leading-5">{{ t('Confirm agency delivery window') }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 pl-6">
                                <span class="bg-tag-blue px-1.5 py-0.5 text-2xs font-medium tracking-[0.04em] text-tag-blue-foreground uppercase">{{ t('Sales') }}</span>
                            </div>
                            <div class="flex items-center justify-between pl-6 text-xs text-muted-foreground">
                                <span>{{ t('19 Aug') }}</span>
                                <span class="flex size-6 items-center justify-center rounded-full bg-secondary text-2xs font-semibold text-secondary-foreground">MK</span>
                            </div>
                        </article>
                        <article class="flex flex-col gap-2 border border-border p-3">
                            <div class="flex items-start gap-2">
                                <CircleCheck class="size-4 shrink-0 text-board-done" :stroke-width="2" aria-hidden="true" />
                                <p class="min-w-0 flex-1 text-sm leading-5 text-muted-foreground line-through">{{ t('Reconcile purchase order #2291') }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 pl-6">
                                <span class="bg-tag-green px-1.5 py-0.5 text-2xs font-medium tracking-[0.04em] text-tag-green-foreground uppercase">{{ t('Supply') }}</span>
                            </div>
                            <p class="pl-6 text-xs text-muted-foreground">{{ t('Done · 14 Aug') }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="border-y border-border py-16 text-center">
                <div class="mx-auto max-w-2xl px-6">
                    <p class="text-xl leading-relaxed font-semibold tracking-tight text-balance sm:text-2xl">
                        {{
                            t(
                                'A card with no data says so. Never a plausible-looking number standing in for one that was never actually measured.',
                            )
                        }}
                    </p>
                    <p class="mt-4 text-sm text-muted-foreground">{{ t('HOW WE BUILD IT') }}</p>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-6 py-16">
                <div class="bg-primary px-8 py-14 text-center text-primary-foreground">
                    <h2 class="text-2xl font-bold tracking-tight text-balance sm:text-3xl">
                        {{ t('Bring the whole estate onto one ledger.') }}
                    </h2>
                    <p class="mx-auto mt-3 max-w-[44ch] opacity-75">
                        {{ t('See your own vineyards, vessels, and channels in a working dashboard, not a slide deck.') }}
                    </p>
                    <div class="mt-7 flex flex-wrap justify-center gap-3">
                        <a
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-background px-6 text-base font-medium text-foreground transition-colors hover:bg-background/90"
                            href="mailto:hello@terroirbi.com"
                        >
                            {{ t('Request a demo') }}
                        </a>
                        <a
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-primary-foreground/35 bg-transparent px-6 text-base font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/10"
                            href="#platform"
                        >
                            {{ t('Explore the platform') }}
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-border">
            <div class="mx-auto max-w-6xl px-6 py-12">
                <div class="grid grid-cols-1 gap-9 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <AppLogo />
                            <span><p class="text-13 font-semibold">{{ t('Terroir') }}</p><p class="text-2xs text-muted-foreground">{{ t('Business Intelligence') }}</p></span>
                        </div>
                        <p class="mt-3 max-w-[28ch] text-sm text-muted-foreground">
                            {{ t('The operating system for a wine estate, from the vine to the bank account.') }}
                        </p>
                    </div>
                    <div>
                        <h4 class="mb-3 text-2xs font-semibold tracking-wider text-muted-foreground uppercase">{{ t('Platform') }}</h4>
                        <a href="#platform" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Overview') }}</a>
                        <a href="#channels" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Sales') }}</a>
                        <a href="#workflow" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Production') }}</a>
                        <a href="#platform" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Supply') }}</a>
                    </div>
                    <div>
                        <h4 class="mb-3 text-2xs font-semibold tracking-wider text-muted-foreground uppercase">{{ t('Operations') }}</h4>
                        <a href="#platform" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Finance') }}</a>
                        <a href="#platform" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Team') }}</a>
                        <a href="#board" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Work Orders') }}</a>
                    </div>
                    <div>
                        <h4 class="mb-3 text-2xs font-semibold tracking-wider text-muted-foreground uppercase">{{ t('Company') }}</h4>
                        <a href="#top" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('About') }}</a>
                        <a href="mailto:hello@terroirbi.com" class="block py-1 text-sm text-muted-foreground hover:text-foreground">{{ t('Contact') }}</a>
                    </div>
                </div>
                <div class="mt-10 flex flex-wrap justify-between gap-2 border-t border-border pt-5 text-xs text-muted-foreground">
                    <p>{{ t('© 2026 Terroir Business Intelligence.') }}</p>
                    <p>{{ t('Built for wine estates, in Croatian and English.') }}</p>
                </div>
            </div>
        </footer>
    </div>
</template>
