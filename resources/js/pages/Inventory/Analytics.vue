<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Plus, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BarChart from '@/components/ui/BarChart.vue';
import BulkImportDialog from '@/components/inventory/BulkImportDialog.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import { categoryLabel, channelLabel, formatMonth } from '@/lib/stock';
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
const props = defineProps<{ analytics: InventoryAnalytics; filters: { months: number } }>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const num = (n: number) => formatNumber(n, locale.value);
const money = (minor: number, currency: string) => formatMoney(minor, currency);

const summary = computed(() => props.analytics.summary);
const exits = computed(() => props.analytics.portfolio_exits);
const value = computed(() => props.analytics.value);

const MODULE_TABS: TabItem[] = [
    { label: t('Inventory'), href: '/inventory' },
    { label: t('Analytics'), href: '/inventory-analytics' },
    { label: t('Inventory Spend'), href: '/inventory-spend' },
    { label: t('Inventory Check'), href: '/inventory-check' },
];

/**
 * Cover at the observed exit rate. Nothing moving means cover is unbounded,
 * which the design surfaces as a warning rather than a number.
 */
const cover = computed(() => {
    const days = exits.value.period_days || 1;
    const perDay = exits.value.external.units_exited / days;

    if (perDay <= 0) return { text: t('no exits'), implausible: true };

    const coverDays = summary.value.finished_units / perDay;

    if (coverDays > 730) {
        return { text: t(':n years', { n: Math.round(coverDays / 365) }), implausible: true };
    }

    return { text: `${Math.round(coverDays)} d`, implausible: false };
});

/** Nothing costed means the portfolio total is the only value we can state. */
const nothingCosted = computed(() => summary.value.costed_count === 0);

/** The "In and out" card's range picker (Figma 382:1592). */
const RANGE_TABS: TabItem[] = [
    { value: '3', label: t('3 months') },
    { value: '6', label: t('6 months') },
    { value: '12', label: t('12 months') },
];

function selectMonths(months: string): void {
    router.get(
        '/inventory-analytics',
        { months },
        { preserveState: true, preserveScroll: true, only: ['analytics', 'filters'] },
    );
}

/** The "Stock against movement" card's unit switch (Figma 382:1592). */
type StockUnit = 'bottles' | 'cases' | 'value';

const STOCK_UNIT_TABS: TabItem[] = [
    { value: 'bottles', label: t('Bottles') },
    { value: 'cases', label: t('Cases') },
    { value: 'value', label: t('Value') },
];

const stockUnit = ref<StockUnit>('bottles');

/**
 * A level's bar amount in the selected unit — null when the item can't be
 * expressed in it (cases need a case size, value needs a price), so the row
 * says so rather than drawing a bar it cannot honestly compute.
 */
function levelAmount(level: InventoryAnalytics['stock_levels'][number]): number | null {
    const bottles = Number.parseFloat(level.stock) || 0;

    if (stockUnit.value === 'bottles') return bottles;
    if (stockUnit.value === 'cases') return level.bottles_per_case ? bottles / level.bottles_per_case : null;

    return level.value; // already in minor currency units
}

function levelLabel(level: InventoryAnalytics['stock_levels'][number]): string {
    const amount = levelAmount(level);

    if (amount === null) {
        return stockUnit.value === 'cases' ? t('no case size') : t('not priced');
    }

    if (stockUnit.value === 'value') return money(amount, value.value.currency);

    return t(':count :unit', { count: num(Math.round(amount * 100) / 100), unit: stockUnit.value === 'cases' ? t('cases') : t('held') });
}

const maxStockAmount = computed(() =>
    Math.max(1, ...props.analytics.stock_levels.map((s) => levelAmount(s) ?? 0)),
);

const channelUnits = computed(() => exits.value.channels.reduce((sum, c) => sum + c.units, 0));

const bulkImportOpen = ref(false);
</script>

<template>
    <AppLayout :title="t('Inventory analytics')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Inventory')">
                <template #actions>
                    <Button v-if="can('inventory.manage')" variant="outline" size="sm" @click="bulkImportOpen = true">
                        <Upload class="size-4" :stroke-width="1.5" />
                        {{ t('Bulk Import') }}
                    </Button>
                    <Button v-if="can('inventory.manage')" size="sm" href="/inventory">
                        <Plus class="size-4" :stroke-width="1.5" />
                        {{ t('New Item') }}
                    </Button>
                </template>
            </PageHeader>
            <Tabs :items="MODULE_TABS" :current="t('Analytics')" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :label="t('Stock on hand')"
                    :value="num(summary.finished_units)"
                    :hint="t('finished goods · :count products', { count: num(summary.finished_products) })"
                />
                <StatCard
                    :label="t('Stock value')"
                    :value="money(value.total, value.currency)"
                    :hint="t('finished goods · portfolio only')"
                />
                <StatCard
                    :label="t('Left the cellar · :days days', { days: exits.period_days })"
                    :value="num(exits.external.units_exited)"
                    :hint="t(':count :channels moved', { count: num(exits.channels.length), channels: exits.channels.length === 1 ? t('channel') : t('channels') })"
                />
                <div
                    class="rounded-lg border p-5"
                    :class="cover.implausible ? 'border-destructive/40 bg-destructive/5' : 'border-border bg-card'"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-medium text-muted-foreground">{{ t('Cover at that rate') }}</p>
                        <AlertTriangle
                            v-if="cover.implausible"
                            class="size-4 shrink-0 text-destructive"
                            :stroke-width="1.5"
                        />
                    </div>
                    <p
                        class="mt-2 text-3xl font-semibold tabular-nums"
                        :class="cover.implausible && 'text-destructive'"
                    >
                        {{ cover.text }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ cover.implausible ? t('too little movement to be meaningful') : t('at the observed exit rate') }}
                    </p>
                </div>
            </section>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            :title="t('In and out · :count months', { count: filters.months })"
                            :description="t('Units received against units shipped.')"
                        >
                            <template #actions>
                                <Tabs
                                    :items="RANGE_TABS"
                                    :current="String(filters.months)"
                                    variant="solid"
                                    @select="selectMonths"
                                />
                            </template>
                        </SectionHeader>
                        <BarChart
                            :points="
                                analytics.movements_12m.map((m) => ({
                                    label: formatMonth(m.month, locale),
                                    values: [m.in, m.out],
                                }))
                            "
                            :series="[t('In · bottling and receipts'), t('Out · shipped')]"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            :title="t('Where it went')"
                            :description="t('Of the :count units that moved.', { count: num(exits.external.units_exited) })"
                        />

                        <ul v-if="exits.channels.length" class="flex flex-col gap-3">
                            <li v-for="channel in exits.channels" :key="channel.key" class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium">{{ channelLabel(channel.key) }}</span>
                                    <span class="shrink-0 text-xs tabular-nums">
                                        {{ num(channel.units) }} ·
                                        {{ channel.revenue ? money(channel.revenue.minor, channel.revenue.currency) : t('no revenue') }}
                                    </span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden bg-muted">
                                    <div
                                        class="h-full bg-foreground/70"
                                        :style="{ width: `${channelUnits > 0 ? (channel.units / channelUnits) * 100 : 0}%` }"
                                    />
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">{{ t('Nothing left the cellar in this window.') }}</p>

                        <Separator v-if="analytics.by_group.length" />

                        <ul v-if="analytics.by_group.length" class="flex flex-col gap-2">
                            <li
                                v-for="group in analytics.by_group"
                                :key="group.group ?? 'ungrouped'"
                                class="flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span class="truncate">{{ group.group ?? t('Ungrouped') }}</span>
                                <span class="shrink-0 text-muted-foreground tabular-nums">
                                    {{ num(group.count) }} {{ group.count === 1 ? t('product') : t('products') }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            :title="t('Stock against movement')"
                            :description="t('Bar is what we hold. Per-product exits are not attributed yet, so no movement notch is drawn.')"
                        >
                            <template #actions>
                                <Tabs :items="STOCK_UNIT_TABS" :current="stockUnit" variant="filter" @select="stockUnit = $event as StockUnit" />
                            </template>
                        </SectionHeader>

                        <ul v-if="analytics.stock_levels.length" class="flex flex-col gap-3">
                            <li v-for="level in analytics.stock_levels" :key="level.name" class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium">{{ level.name }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                                        {{ levelLabel(level) }}
                                    </span>
                                </div>
                                <div class="h-2 w-full overflow-hidden bg-muted">
                                    <div
                                        class="h-full bg-muted-foreground/50"
                                        :style="{ width: `${((levelAmount(level) ?? 0) / maxStockAmount) * 100}%` }"
                                    />
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">{{ t('No active stock to show.') }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader :title="t('Stock value')" />

                        <p class="text-3xl font-semibold tabular-nums">{{ money(value.total, value.currency) }}</p>
                        <p class="-mt-2 text-xs text-muted-foreground">
                            {{ t('Finished goods · :count units', { count: num(summary.finished_units) }) }}
                        </p>

                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="category in value.categories"
                                :key="category.category"
                                class="flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span class="truncate">{{ categoryLabel(category.category) }}</span>
                                <span class="shrink-0 tabular-nums">
                                    {{ category.value > 0 ? money(category.value, value.currency) : t('not valued') }}
                                </span>
                            </li>
                        </ul>

                        <!--
                          The design's data-quality callout. The wording reflects
                          how many items are actually costed, but the section and
                          its action are always present so the card matches the
                          design whatever the data says.
                        -->
                        <Callout
                            :title="nothingCosted ? t('No value per product') : t('Value is only as good as the costs')"
                            :tone="nothingCosted ? 'warning' : 'neutral'"
                        >
                            <template v-if="nothingCosted">
                                {{ t('Cost per unit is empty on every active item, so value can only be totalled at portfolio level.') }}
                            </template>
                            <template v-else>
                                {{ t(':costed of :total active items carry a cost per unit. The rest fall back to list price.', { costed: num(summary.costed_count), total: num(summary.total_active) }) }}
                            </template>
                            <template #action>
                                <Button v-if="can('inventory.manage')" variant="outline" size="sm" href="/inventory?missing_cost=1">
                                    {{ t('Add costs') }}
                                </Button>
                            </template>
                        </Callout>
                    </CardContent>
                </Card>
            </div>
        </div>

        <BulkImportDialog :open="bulkImportOpen" @close="bulkImportOpen = false" />
    </AppLayout>
</template>
