<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Download, Plus, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type { CustomerAnalytics } from '@/types/customers';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * Customers · Analytics (Figma 230:4717): four headline cards over a table
 * ranked by the last twelve months' revenue.
 *
 * The whole table is one query result rather than a paginated list — it is a
 * ranking, and a ranking you can only see 25 rows of is not a ranking. Filtering
 * is therefore client-side, over data already on the page.
 */
const props = defineProps<{ analytics: CustomerAnalytics }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

const MODULE_TABS = computed<TabItem[]>(() => [
    { label: t('Customers'), href: '/customers' },
    { label: t('Analytics'), href: '/customers-analytics' },
]);

const filter = ref('');

const rows = computed(() => {
    const term = filter.value.trim().toLowerCase();

    if (term === '') return props.analytics.customers;

    return props.analytics.customers.filter(
        (row) =>
            row.company_name.toLowerCase().includes(term) ||
            (row.contact_name ?? '').toLowerCase().includes(term),
    );
});

const money = (m: { minor: number; currency: string }): string =>
    formatMoney(m.minor, m.currency, locale.value);

/**
 * The design's "Rebate performance" card reports what rebates cost across the
 * book. That figure is not in CustomerAnalyticsQuery — it would need rebate
 * applied per line, which the order lines do not keep separately — so the
 * fourth card reports average order value instead, which the query does supply.
 * The divergence is in docs/design/README.md.
 */
const avgOrderValue = computed(() => {
    const totals = props.analytics.customers.reduce(
        (acc, row) => {
            acc.minor += row.avg_order_value.minor * row.order_count_12m;
            acc.orders += row.order_count_12m;

            return acc;
        },
        { minor: 0, orders: 0 },
    );

    return {
        minor: totals.orders > 0 ? Math.round(totals.minor / totals.orders) : 0,
        currency: props.analytics.summary.revenue_12m.currency,
        orders: totals.orders,
    };
});

function relativeDays(days: number | null): string {
    if (days === null) return t('never');
    if (days === 0) return t('today');

    return t(':count days ago', { count: formatNumber(days, locale.value) });
}

function shortDate(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleDateString(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    });
}
</script>

<template>
    <AppLayout :title="t('Customer analytics')">
        <div class="space-y-5">
            <PageHeader :title="t('Customers')">
                <template #actions>
                    <!-- @todo Export all — no customer export endpoint yet. -->
                    <Button variant="outline" size="sm">
                        <Download class="size-3.5" :stroke-width="1.5" />
                        {{ t('Export all') }}
                    </Button>
                    <Button size="sm" href="/customers">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New Customer') }}
                    </Button>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" :current="t('Analytics')" />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :label="t('Active customers')"
                    :value="formatNumber(analytics.summary.active_customers, locale)"
                    :hint="t('at least one order')"
                />
                <StatCard
                    :label="t('Revenue last 12 months')"
                    :value="money(analytics.summary.revenue_12m)"
                    :hint="t('across all customers')"
                />
                <StatCard
                    :label="t('Top customer (12m)')"
                    :value="analytics.summary.top_customer?.company_name ?? '—'"
                    :hint="
                        analytics.summary.top_customer
                            ? money(analytics.summary.top_customer.revenue_12m)
                            : t('no revenue in the window')
                    "
                />
                <StatCard
                    :label="t('Average order value')"
                    :value="money({ minor: avgOrderValue.minor, currency: avgOrderValue.currency })"
                    :hint="t('across :count orders in 12 months', { count: formatNumber(avgOrderValue.orders, locale) })"
                />
            </section>

            <div class="relative w-full max-w-[280px]">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    :stroke-width="1.5"
                />
                <input
                    v-model="filter"
                    type="search"
                    :placeholder="t('Filter customers…')"
                    :aria-label="t('Filter customers')"
                    class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[64rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Company') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Contact') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('Revenue last 12m ↓') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('All-time') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('Orders (12m)') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('Avg order') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('Last order') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('Typical gap') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    {{ t('Expected by') }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.customer_id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="`/customers/${row.customer_id}`"
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        {{ row.company_name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ row.contact_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums">
                                    {{ money(row.revenue_12m) }}
                                </td>
                                <td class="px-4 py-3 text-right text-muted-foreground tabular-nums">
                                    {{ money(row.revenue_all_time) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatNumber(row.order_count_12m, locale) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ money(row.avg_order_value) }}</td>
                                <td class="px-4 py-3 text-right text-muted-foreground">
                                    {{ relativeDays(row.days_since_last_order) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ row.median_gap_days === null ? '—' : t(':count d', { count: row.median_gap_days }) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ shortDate(row.expected_next_order_date) }}
                                </td>
                            </tr>

                            <tr v-if="rows.length === 0">
                                <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No customers with orders yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
