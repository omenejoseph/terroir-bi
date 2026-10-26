<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

import BarChart from '@/components/ui/BarChart.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import Select from '@/components/ui/Select.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { AgencyPerformance, AgencyPerformanceRow, HospitalityAnalytics } from '@/types/hospitality';
import type { MoneyValue } from '@/types/inventory';
import type { DateRange } from '@/types/ui';

/**
 * Hospitality · Analytics (the old app's hospitality-analytics): a year's KPIs, revenue by month
 * against the year before, programs, top agencies, and the agency performance table with its own
 * date range and an optional comparison with the period just before.
 */
const props = defineProps<{
    analytics: HospitalityAnalytics | undefined;
    performance: AgencyPerformance | undefined;
    compare: boolean;
}>();

const emit = defineEmits<{
    year: [year: number];
    performance: [range: { from: string | null; to: string | null; compare: boolean }];
}>();

const { t } = useTranslations();
const locale = computed(() => usePage<SharedProps>().props.locale);

const money = (m: Pick<MoneyValue, 'minor' | 'currency'> | null | undefined): string => (m ? formatMoney(m.minor, m.currency) : '—');

const yearOptions = computed(() => (props.analytics?.years ?? []).map((y) => ({ value: String(y), label: String(y) })));

const kpis = computed(() => {
    const k = props.analytics?.kpis;
    if (!k) return [];

    return [
        { key: 'reservations', label: t('Reservations'), value: formatNumber(k.reservations, locale.value) },
        { key: 'revenue', label: t('Revenue'), value: money(k.revenue) },
        { key: 'guests', label: t('Guests'), value: formatNumber(k.guests, locale.value) },
        { key: 'per-guest', label: t('Revenue per guest'), value: money(k.revenue_per_guest) },
        { key: 'upcoming', label: t('Upcoming'), value: formatNumber(k.upcoming, locale.value) },
        { key: 'pipeline', label: t('Pipeline'), value: money(k.pipeline), hint: t('Upcoming bookings not yet completed') },
        {
            key: 'conversion',
            label: t('Conversion rate'),
            value: k.conversion_rate === null ? '—' : `${k.conversion_rate}%`,
            hint: t('Confirmed or further, of all bookings'),
        },
    ];
});

const monthPoints = computed(() =>
    (props.analytics?.months ?? []).map((m) => ({
        label: new Date(2000, m.month - 1, 1).toLocaleDateString(locale.value, { month: 'short' }),
        values: [m.revenue.minor / 100, m.previous_revenue.minor / 100],
    })),
);

const topAgencyMax = computed(() => Math.max(1, ...(props.analytics?.top_agencies ?? []).map((a) => a.revenue.minor)));

/* ---- agency performance ------------------------------------------------------- */

const range = computed<DateRange>(() => ({ from: props.performance?.from ?? null, to: props.performance?.to ?? null }));

type SortKey = 'name' | 'reservations' | 'guests' | 'revenue' | 'revenue_per_guest' | 'discount' | 'discount_percent' | 'change_percent';
const sortKey = ref<SortKey>('revenue');
const sortDesc = ref(true);

function sortValue(row: AgencyPerformanceRow, key: SortKey): number | string {
    switch (key) {
        case 'name':
            return row.name.toLowerCase();
        case 'revenue':
        case 'revenue_per_guest':
        case 'discount':
            return row[key].minor;
        default:
            return row[key] ?? -Infinity;
    }
}

const sortedRows = computed(() => {
    const rows = [...(props.performance?.rows ?? [])];

    rows.sort((a, b) => {
        const x = sortValue(a, sortKey.value);
        const y = sortValue(b, sortKey.value);
        const order = x < y ? -1 : x > y ? 1 : 0;

        return sortDesc.value ? -order : order;
    });

    return rows;
});

function sortBy(key: SortKey): void {
    if (sortKey.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortKey.value = key;
        sortDesc.value = key !== 'name';
    }
}

const columns = computed<{ key: SortKey; label: string; numeric: boolean }[]>(() => [
    { key: 'name', label: t('Agency'), numeric: false },
    { key: 'reservations', label: t('Reservations'), numeric: true },
    { key: 'guests', label: t('Guests'), numeric: true },
    { key: 'revenue', label: t('Revenue'), numeric: true },
    { key: 'revenue_per_guest', label: t('Per guest'), numeric: true },
    { key: 'discount', label: t('Discount given'), numeric: true },
    { key: 'discount_percent', label: t('Discount %'), numeric: true },
    ...(props.compare ? [{ key: 'change_percent' as SortKey, label: t('Change'), numeric: true }] : []),
]);

const totals = computed(() => {
    const rows = props.performance?.rows ?? [];
    const currency = rows[0]?.revenue.currency ?? 'EUR';
    const sum = (pick: (r: AgencyPerformanceRow) => number): number => rows.reduce((s, r) => s + pick(r), 0);

    return {
        reservations: sum((r) => r.reservations),
        guests: sum((r) => r.guests),
        revenue: { minor: sum((r) => r.revenue.minor), currency },
        discount: { minor: sum((r) => r.discount.minor), currency },
        list: sum((r) => r.list_value.minor),
    };
});
</script>

<template>
    <div class="space-y-5">
        <p v-if="!analytics" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>

        <template v-else>
            <div class="flex items-center gap-2">
                <label for="analytics-year" class="text-xs text-muted-foreground">{{ t('Year') }}</label>
                <div class="w-28">
                    <Select
                        id="analytics-year"
                        :model-value="String(analytics.year)"
                        :options="yearOptions"
                        class="h-[30px] text-xs"
                        @update:model-value="(v) => emit('year', Number(v))"
                    />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                <StatCard v-for="k in kpis" :key="k.key" :label="k.label" :value="k.value" :hint="k.hint" />
            </div>

            <section class="border border-border bg-card p-4">
                <h3 class="text-sm font-semibold">{{ t('Revenue by month') }}</h3>
                <BarChart class="mt-3" :points="monthPoints" :series="[String(analytics.year), String(analytics.year - 1)]" :height="220" />
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Programs') }}</h3>
                    <p v-if="analytics.programs.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('No reservations this year.') }}</p>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="p in analytics.programs" :key="p.program_id" class="px-4 py-3 text-xs">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate font-medium text-foreground">{{ p.name }}</span>
                                <span class="shrink-0 font-semibold tabular-nums">{{ money(p.revenue) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 bg-muted" aria-hidden="true">
                                <div class="h-full bg-foreground" :style="{ width: `${p.share}%` }" />
                            </div>
                            <p class="mt-1 text-muted-foreground tabular-nums">
                                {{ t(':count reservations', { count: p.reservations }) }} · {{ t(':count guests', { count: p.guests }) }} ·
                                {{ t(':price / guest', { price: money(p.revenue_per_guest) }) }} · {{ p.share }}%
                            </p>
                        </li>
                    </ul>
                </section>

                <section class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Top agencies') }}</h3>
                    <p v-if="analytics.top_agencies.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('No agency reservations this year.') }}</p>
                    <ol v-else class="divide-y divide-border">
                        <li v-for="(a, i) in analytics.top_agencies" :key="a.customer_id" class="px-4 py-3 text-xs">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate"><span class="text-muted-foreground tabular-nums">{{ i + 1 }}.</span> {{ a.name }}</span>
                                <span class="shrink-0 font-semibold tabular-nums">{{ money(a.revenue) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 bg-muted" aria-hidden="true">
                                <div class="h-full bg-foreground" :style="{ width: `${(a.revenue.minor / topAgencyMax) * 100}%` }" />
                            </div>
                            <p class="mt-1 text-muted-foreground">{{ t(':count reservations', { count: a.reservations }) }} · {{ t(':count guests', { count: a.guests }) }}</p>
                        </li>
                    </ol>
                </section>
            </div>

            <section class="border border-border bg-card">
                <div class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
                    <h3 class="mr-auto text-sm font-semibold">{{ t('Agency performance') }}</h3>
                    <DateRangePicker
                        :model-value="range"
                        :label="t('Date range')"
                        :max="null"
                        @update:model-value="(r: DateRange) => emit('performance', { from: r.from, to: r.to, compare })"
                    />
                    <Checkbox
                        :model-value="compare"
                        :label="t('Compare to previous period')"
                        @update:model-value="(v: boolean) => emit('performance', { from: range.from, to: range.to, compare: v })"
                    />
                </div>
                <p v-if="performance && compare && performance.previous_from" class="border-b border-border px-4 py-2 text-xs text-muted-foreground">
                    {{ t('Compared with :from – :to', { from: performance.previous_from, to: performance.previous_to ?? '' }) }}
                </p>

                <p v-if="!performance" class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('Loading…') }}</p>
                <p v-else-if="performance.rows.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">
                    {{ t('No agency reservations in this period.') }}
                </p>
                <!-- The table scrolls inside its own container so the page never does. -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                            <tr>
                                <th
                                    v-for="col in columns"
                                    :key="col.key"
                                    scope="col"
                                    class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase"
                                    :class="col.numeric ? 'text-right' : ''"
                                    :aria-sort="sortKey === col.key ? (sortDesc ? 'descending' : 'ascending') : 'none'"
                                >
                                    <button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" @click="sortBy(col.key)">
                                        {{ col.label }}
                                        <ArrowDown v-if="sortKey === col.key && sortDesc" class="size-3" :stroke-width="2" />
                                        <ArrowUp v-else-if="sortKey === col.key" class="size-3" :stroke-width="2" />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in sortedRows" :key="row.customer_id" class="border-b border-border last:border-b-0">
                                <td class="px-4 py-2.5 font-medium text-foreground">{{ row.name }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ row.reservations }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ row.guests }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums">
                                    {{ money(row.revenue) }}
                                    <span v-if="compare && row.previous_revenue" class="block font-normal text-muted-foreground">{{ money(row.previous_revenue) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ money(row.revenue_per_guest) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ money(row.discount) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ row.discount_percent === null ? '—' : `${row.discount_percent}%` }}</td>
                                <td v-if="compare" class="px-4 py-2.5 text-right tabular-nums" :class="(row.change_percent ?? 0) < 0 ? 'text-destructive' : ''">
                                    {{ row.change_percent === null ? '—' : `${row.change_percent > 0 ? '+' : ''}${row.change_percent}%` }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-border bg-muted/40 font-semibold">
                            <tr>
                                <td class="px-4 py-2.5">{{ t('Total') }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ totals.reservations }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ totals.guests }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ money(totals.revenue) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ totals.guests > 0 ? formatMoney(Math.floor(totals.revenue.minor / totals.guests), totals.revenue.currency) : '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ money(totals.discount) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ totals.list > 0 ? `${Math.round((totals.discount.minor / totals.list) * 1000) / 10}%` : '—' }}
                                </td>
                                <td v-if="compare" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
