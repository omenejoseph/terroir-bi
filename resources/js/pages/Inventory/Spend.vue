<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import SparkBars from '@/components/inventory/SparkBars.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { formatMoney, formatNumber } from '@/lib/money';
import { coverPhrase, spendSignal } from '@/lib/stock';
import type { InventoryAnalytics, InventorySpend } from '@/types/stock';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * Inventory spend (Figma `386:1673`) — capital tied up against what actually
 * left, per product.
 *
 * Built from the cached layer tree and copy rather than a render (this frame
 * was not in the export set), so its appearance follows the shared components.
 * Worth a visual diff against `386:1673` when a render exists.
 */
const props = defineProps<{
    spend: InventorySpend;
    portfolio: { value: InventoryAnalytics['value']; summary: InventoryAnalytics['summary'] };
    filters: { preset: string };
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const num = (n: number) => formatNumber(n, locale.value);
const money = (minor: number, currency: string) => formatMoney(minor, currency, locale.value);

const MODULE_TABS: TabItem[] = [
    { label: 'Inventory', href: '/inventory' },
    { label: 'Analytics', href: '/inventory-analytics' },
    { label: 'Inventory Spend', href: '/inventory-spend' },
    { label: 'Inventory Check', href: '/inventory-check' },
];

const currency = computed(() => props.portfolio.value.currency);
const summary = computed(() => props.spend.summary);

/** Share of stock value that came back as revenue in the window. */
const returnShare = computed(() => {
    const total = props.portfolio.value.total;

    return total > 0 ? (summary.value.revenue.minor / total) * 100 : null;
});

const grossProfit = computed(() => summary.value.revenue.minor - summary.value.cost_value.minor);
const grossMargin = computed(() =>
    summary.value.revenue.minor > 0 ? (grossProfit.value / summary.value.revenue.minor) * 100 : null,
);

/** Products that moved nothing — the design's "Sitting untouched" tile. */
const untouched = computed(() => props.spend.per_product.filter((p) => p.units_exited === 0));

const dailyUnits = computed(() => props.spend.daily.map((d) => d.units));

const dateRange = computed(() => {
    const fmt = new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' });

    return `${fmt.format(new Date(props.spend.period.from))} – ${fmt.format(new Date(props.spend.period.to))}`;
});
</script>

<template>
    <AppLayout title="Inventory spend">
        <div class="flex flex-col gap-5">
            <PageHeader title="Inventory" />
            <Tabs :items="MODULE_TABS" current="Inventory Spend" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Capital tied up"
                    :value="money(portfolio.value.total, currency)"
                    hint="in finished goods on hand"
                />
                <StatCard
                    :label="`Returned in ${spend.period.days} days`"
                    :value="money(summary.revenue.minor, summary.revenue.currency)"
                    :hint="
                        returnShare !== null
                            ? `${returnShare.toFixed(1)} % of the stock value`
                            : 'no stock value to compare'
                    "
                />
                <StatCard
                    label="Cost of what left"
                    :value="money(summary.cost_value.minor, summary.cost_value.currency)"
                    :hint="`against ${money(summary.revenue.minor, summary.revenue.currency)} revenue`"
                />
                <StatCard
                    label="Sitting untouched"
                    :value="num(untouched.length)"
                    :hint="`of ${num(spend.per_product.length)} products, no exits`"
                />
            </section>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            title="Units leaving per day"
                            :description="`${num(summary.units_exited)} units across ${spend.period.days} days — the whole cellar`"
                        >
                            <template #actions>
                                <span class="text-13 text-muted-foreground">{{ dateRange }}</span>
                            </template>
                        </SectionHeader>
                        <SparkBars :values="dailyUnits" unit="units" />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader title="What it earned" />

                        <p class="text-3xl font-semibold tabular-nums">
                            {{ money(summary.revenue.minor, summary.revenue.currency) }}
                        </p>

                        <Separator />

                        <div class="flex flex-col gap-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm">Gross profit</span>
                                <span class="text-sm font-medium tabular-nums">
                                    {{ money(grossProfit, currency) }}
                                    <span v-if="grossMargin !== null" class="text-muted-foreground">
                                        · {{ grossMargin.toFixed(1) }} %
                                    </span>
                                </span>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm">Cost of goods</span>
                                <span class="text-sm font-medium tabular-nums">
                                    {{ money(summary.cost_value.minor, summary.cost_value.currency) }}
                                    <span v-if="grossMargin !== null" class="text-muted-foreground">
                                        · {{ (100 - grossMargin).toFixed(1) }} %
                                    </span>
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardContent class="flex flex-col gap-4 p-6">
                    <SectionHeader
                        title="Depletion and return by product"
                        description="Runout forecast and per-product exit, merged into one table."
                    />

                    <div v-if="spend.per_product.length" class="overflow-x-auto">
                        <table class="w-full min-w-[60rem] text-sm">
                            <thead class="border-b border-border text-left text-13 text-muted-foreground">
                                <tr>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Product</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">SKU</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">On hand</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">
                                        Left {{ spend.period.days }}d
                                    </th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">Per day</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Cover</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">Cost</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">Revenue</th>
                                    <th scope="col" class="py-2.5 font-medium">Signal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="row in spend.per_product" :key="row.id">
                                    <td class="py-3 pr-4">
                                        <span class="font-medium">{{ row.name }}</span>
                                        <span v-if="row.vintage" class="ml-1.5 text-muted-foreground">
                                            {{ row.vintage }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap text-muted-foreground">{{ row.sku }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ num(row.on_hand) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ num(row.units_exited) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums">
                                        {{ row.units_exited > 0 ? row.velocity_per_day : '—' }}
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap text-muted-foreground">
                                        {{ coverPhrase(row.days_left, row.units_exited) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums">
                                        {{ row.cost_of_exits ? money(row.cost_of_exits.minor, row.cost_of_exits.currency) : '—' }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums">
                                        {{ row.revenue ? money(row.revenue.minor, row.revenue.currency) : '—' }}
                                    </td>
                                    <td class="py-3">
                                        <span
                                            class="text-13"
                                            :class="
                                                spendSignal(row).tone === 'warn'
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{ spendSignal(row).text }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-6 text-sm text-muted-foreground">
                        Nothing left the cellar in this window.
                    </p>

                    <p v-if="untouched.length" class="text-13 text-destructive">
                        {{ num(untouched.length) }} of {{ num(spend.per_product.length) }} products have no spend or
                        return to report in this window.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
