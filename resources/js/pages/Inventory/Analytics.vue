<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import InOutChart from '@/components/inventory/InOutChart.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { formatMoney, formatNumber, formatQuantity } from '@/lib/money';
import { categoryLabel, channelLabel } from '@/lib/stock';
import type { InventoryAnalytics } from '@/types/stock';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * Inventory analytics (Figma `382:1592`).
 *
 * The design is data-quality aware: it turns the cover figure red and explains
 * why when the numbers are implausible, rather than presenting a nonsense
 * value as fact. That behaviour is reproduced here — a portfolio that barely
 * moves really does compute to decades of cover, and saying so is the point.
 */
const props = defineProps<{ analytics: InventoryAnalytics }>();

const page = usePage<SharedProps>();
const { can } = useAuth();

const locale = computed(() => page.props.locale);
const num = (n: number) => formatNumber(n, locale.value);
const money = (minor: number, currency: string) => formatMoney(minor, currency, locale.value);

const summary = computed(() => props.analytics.summary);
const exits = computed(() => props.analytics.portfolio_exits);
const value = computed(() => props.analytics.value);

const MODULE_TABS: TabItem[] = [
    { label: 'Inventory', href: '/inventory' },
    { label: 'Analytics', href: '/inventory-analytics' },
    { label: 'Inventory Spend', href: null },
    { label: 'Inventory Check', href: null },
];

/**
 * Cover at the observed exit rate. Nothing moving means cover is unbounded,
 * which the design surfaces as a warning rather than a number.
 */
const cover = computed(() => {
    const days = exits.value.period_days || 1;
    const perDay = exits.value.external.units_exited / days;

    if (perDay <= 0) return { text: 'no exits', implausible: true };

    const coverDays = summary.value.finished_units / perDay;

    if (coverDays > 730) {
        return { text: `${Math.round(coverDays / 365)} years`, implausible: true };
    }

    return { text: `${Math.round(coverDays)} d`, implausible: false };
});

/** Nothing costed means the portfolio total is the only value we can state. */
const nothingCosted = computed(() => summary.value.costed_count === 0);

const maxStock = computed(() =>
    Math.max(1, ...props.analytics.stock_levels.map((s) => Number.parseFloat(s.stock) || 0)),
);

const channelUnits = computed(() => exits.value.channels.reduce((sum, c) => sum + c.units, 0));
</script>

<template>
    <AppLayout title="Inventory analytics">
        <div class="flex flex-col gap-5">
            <PageHeader title="Inventory" />
            <Tabs :items="MODULE_TABS" current="Analytics" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Stock on hand"
                    :value="num(summary.finished_units)"
                    :hint="`finished goods · ${num(summary.finished_products)} products`"
                />
                <StatCard
                    label="Stock value"
                    :value="money(value.total, value.currency)"
                    hint="finished goods · portfolio only"
                />
                <StatCard
                    :label="`Left the cellar · ${exits.period_days} days`"
                    :value="num(exits.external.units_exited)"
                    :hint="`${num(exits.channels.length)} ${exits.channels.length === 1 ? 'channel' : 'channels'} moved`"
                />
                <div
                    class="rounded-lg border p-5"
                    :class="cover.implausible ? 'border-destructive/40 bg-destructive/5' : 'border-border bg-card'"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-medium text-muted-foreground">Cover at that rate</p>
                        <AlertTriangle
                            v-if="cover.implausible"
                            class="size-4 shrink-0 text-destructive"
                            :stroke-width="1.5"
                        />
                    </div>
                    <p
                        class="mt-2 text-2xl font-semibold tracking-tight tabular-nums"
                        :class="cover.implausible && 'text-destructive'"
                    >
                        {{ cover.text }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ cover.implausible ? 'too little movement to be meaningful' : 'at the observed exit rate' }}
                    </p>
                </div>
            </section>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            title="In and out · 12 months"
                            description="Units received against units shipped."
                        />
                        <InOutChart :points="analytics.movements_12m" :locale="locale" />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            title="Where it went"
                            :description="`Of the ${num(exits.external.units_exited)} units that moved.`"
                        />

                        <ul v-if="exits.channels.length" class="flex flex-col gap-3">
                            <li v-for="channel in exits.channels" :key="channel.key" class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium">{{ channelLabel(channel.key) }}</span>
                                    <span class="shrink-0 text-13 tabular-nums">
                                        {{ num(channel.units) }} ·
                                        {{ channel.revenue ? money(channel.revenue.minor, channel.revenue.currency) : 'no revenue' }}
                                    </span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-foreground/70"
                                        :style="{ width: `${channelUnits > 0 ? (channel.units / channelUnits) * 100 : 0}%` }"
                                    />
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">Nothing left the cellar in this window.</p>

                        <Separator v-if="analytics.by_group.length" />

                        <ul v-if="analytics.by_group.length" class="flex flex-col gap-2">
                            <li
                                v-for="group in analytics.by_group"
                                :key="group.group ?? 'ungrouped'"
                                class="flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span class="truncate">{{ group.group ?? 'Ungrouped' }}</span>
                                <span class="shrink-0 text-muted-foreground tabular-nums">
                                    {{ num(group.count) }} {{ group.count === 1 ? 'product' : 'products' }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            title="Stock against movement"
                            description="Bar is what we hold. Per-product exits are not attributed yet, so no movement notch is drawn."
                        />

                        <ul v-if="analytics.stock_levels.length" class="flex flex-col gap-3">
                            <li v-for="level in analytics.stock_levels" :key="level.name" class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium">{{ level.name }}</span>
                                    <span class="shrink-0 text-13 tabular-nums text-muted-foreground">
                                        {{ formatQuantity(level.stock, locale) }} held
                                    </span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-muted-foreground/50"
                                        :style="{
                                            width: `${((Number.parseFloat(level.stock) || 0) / maxStock) * 100}%`,
                                        }"
                                    />
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">No active stock to show.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader title="Stock value" />

                        <p class="text-3xl font-semibold tabular-nums">{{ money(value.total, value.currency) }}</p>
                        <p class="-mt-2 text-13 text-muted-foreground">
                            Finished goods · {{ num(summary.finished_units) }} units
                        </p>

                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="category in value.categories"
                                :key="category.category"
                                class="flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span class="truncate">{{ categoryLabel(category.category) }}</span>
                                <span class="shrink-0 tabular-nums">
                                    {{ category.value > 0 ? money(category.value, value.currency) : 'not valued' }}
                                </span>
                            </li>
                        </ul>

                        <!-- The design's data-quality callout, shown only when it is true. -->
                        <div v-if="nothingCosted" class="rounded-lg border border-dashed border-border p-4">
                            <p class="text-sm font-medium">No value per product</p>
                            <p class="mt-1 text-13 text-muted-foreground">
                                Cost per unit is empty on every active item, so value can only be totalled at
                                portfolio level.
                            </p>
                            <Button v-if="can('inventory.manage')" variant="outline" size="sm" class="mt-3" href="/inventory">
                                Add costs
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
