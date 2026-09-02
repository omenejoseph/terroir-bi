<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Download, Plus, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BarChart from '@/components/ui/BarChart.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
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

/**
 * The design turns two tiles red (Figma 386:1673). Both are real conditions,
 * not emphasis: a return far below what the stock value implies, and most of
 * the portfolio not moving at all.
 */
const RETURN_SHARE_FLOOR = 5; // percent of stock value returned in the window
const UNTOUCHED_SHARE_CEILING = 0.5; // fraction of products with no exits

const returnIsLow = computed(() => returnShare.value !== null && returnShare.value < RETURN_SHARE_FLOOR);
const tooManyUntouched = computed(
    () =>
        props.spend.per_product.length > 0 &&
        untouched.value.length / props.spend.per_product.length > UNTOUCHED_SHARE_CEILING,
);

/** Daily exits, labelled the way the design labels them (01.08, 02.08 …). */
const dailyPoints = computed(() =>
    props.spend.daily.map((d) => ({
        label: new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit' }).format(new Date(d.date)),
        values: [d.units],
    })),
);

/**
 * The design labels the window by its preset ("90 days", "Left 90d"), not by
 * the inclusive day count the query returns (91). Follow the design.
 */
const windowLabel = computed(() => props.filters.preset.replace(/d$/, ''));

/** Capital sitting in products that did not move — the design's headline. */
const untouchedValue = computed(() =>
    untouched.value.reduce((sum, p) => sum + (p.stock_value?.minor ?? 0), 0),
);

const untouchedIsValued = computed(() => untouched.value.some((p) => p.stock_value !== null));

const dateRange = computed(() => {
    const fmt = new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' });

    return `${fmt.format(new Date(props.spend.period.from))} – ${fmt.format(new Date(props.spend.period.to))}`;
});
</script>

<template>
    <AppLayout title="Inventory spend">
        <div class="flex flex-col gap-5">
            <PageHeader title="Inventory">
                <template #actions>
                    <!-- @todo Bulk Import — no import pipeline yet. -->
                    <Button variant="outline" size="sm">
                        <Upload class="size-4" :stroke-width="1.5" />
                        Bulk Import
                    </Button>
                    <Button size="sm" href="/inventory">
                        <Plus class="size-4" :stroke-width="1.5" />
                        New Item
                    </Button>
                </template>
            </PageHeader>
            <Tabs :items="MODULE_TABS" current="Inventory Spend" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Capital tied up"
                    :value="money(portfolio.value.total, currency)"
                    hint="in finished goods on hand"
                />
                <StatCard
                    :label="`Returned in ${windowLabel} days`"
                    :value="money(summary.revenue.minor, summary.revenue.currency)"
                    :alert="returnIsLow"
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
                <!--
                  The design states this as capital, not a count: "148.000 EUR of
                  the 163.502 on hand sits in these four". It falls back to the
                  count when nothing is priced, rather than showing 0 EUR as if
                  the stock were worthless.
                -->
                <StatCard
                    label="Sitting untouched"
                    :value="untouchedIsValued ? money(untouchedValue, currency) : num(untouched.length)"
                    :alert="tooManyUntouched"
                    :hint="`${num(untouched.length)} of ${num(spend.per_product.length)} products, no exits`"
                />
            </section>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            title="Units leaving per day"
                            :description="`${num(summary.units_exited)} units across ${windowLabel} days — the whole cellar`"
                        >
                            <template #actions>
                                <span class="text-xs text-muted-foreground">{{ dateRange }}</span>
                            </template>
                        </SectionHeader>
                        <BarChart :points="dailyPoints" :height="220" />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader title="What it earned" />

                        <p class="text-3xl font-semibold tabular-nums">
                            {{ money(summary.revenue.minor, summary.revenue.currency) }}
                        </p>

                        <StackedBar
                            :segments="[
                                {
                                    label: 'Gross profit',
                                    value: grossProfit,
                                    caption: money(grossProfit, currency),
                                },
                                {
                                    label: 'Cost of goods',
                                    value: summary.cost_value.minor,
                                    caption: money(summary.cost_value.minor, summary.cost_value.currency),
                                },
                            ]"
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardContent class="flex flex-col gap-4 p-6">
                    <SectionHeader
                        title="Depletion and return by product"
                        description="Runout forecast and per-product exit, merged into one table."
                    >
                        <template #actions>
                            <!--
                              @todo Export. Needs a CSV/XLSX endpoint for this
                              query; the browser cannot serialise the paginated
                              server-side result on its own.
                            -->
                            <Button variant="outline" size="sm">
                                <Download class="size-4" :stroke-width="1.5" />
                                Export
                            </Button>
                        </template>
                    </SectionHeader>

                    <div v-if="spend.per_product.length" class="overflow-x-auto">
                        <table class="w-full min-w-[60rem] text-sm">
                            <thead class="border-b border-border text-left text-3xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Product</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">SKU</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">On hand</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">
                                        Left {{ windowLabel }}d
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
                                        <span class="block font-medium">{{ row.name }}</span>
                                        <span v-if="row.vintage" class="block text-xs text-muted-foreground">
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
                                            class="text-xs"
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

                    <Callout
                        v-if="untouched.length"
                        :title="`${num(untouched.length)} of ${num(spend.per_product.length)} products have no spend or return to report`"
                        tone="warning"
                    >
                        They may still appear in shipped orders — an exit that never reached the ledger looks
                        identical to no trade at all. Check the order → stock link before reading this as idle stock.
                        <template #action>
                            <!--
                              @todo "Check order → stock link" — needs a
                              reconciliation view comparing shipped order lines
                              against recorded stock movements.
                            -->
                            <Button variant="outline" size="sm">Check order → stock link</Button>
                        </template>
                    </Callout>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
