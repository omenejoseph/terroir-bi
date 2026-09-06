<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type { ReorderRadar } from '@/types/customers';
import type { SharedProps } from '@/types';

/**
 * Reorder radar — the Dashboard's "Reorder pipeline" card `View all`
 * (Figma `208:5921`; the design has no dedicated frame for the full list, so
 * this is a plain table, not a pixel match). Every flagged account
 * `App\Queries\ReorderRadarQuery` ranks, not just the card's top rows — same
 * query, so the two can't disagree about who's overdue.
 *
 * One query result, not a paginated list — like Customers · Analytics, a
 * ranking you can only see 25 rows of is not a ranking. Filtering is
 * client-side over data already on the page.
 */
const props = defineProps<{ radar: ReorderRadar }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();
const { can } = useAuth();

const filter = ref('');

const rows = computed(() => {
    const term = filter.value.trim().toLowerCase();

    if (term === '') return props.radar.rows;

    return props.radar.rows.filter((row) => row.company_name.toLowerCase().includes(term));
});

const STATUS_LABEL: Record<string, string> = {
    due: t('Due'),
    overdue: t('Overdue'),
    at_risk: t('At risk'),
};

const STATUS_VARIANT: Record<string, 'warning' | 'destructive'> = {
    due: 'warning',
    overdue: 'destructive',
    at_risk: 'destructive',
};

function daysAgo(days: number): string {
    const whole = Math.round(days);

    if (whole <= 0) return t('Today');
    if (whole === 1) return t('1 day ago');

    return t(':count days ago', { count: formatNumber(whole, locale.value) });
}

const markingContacted = ref<string | null>(null);

function markContacted(customerId: string): void {
    markingContacted.value = customerId;
    router.post(
        `/customers/${customerId}/contacted`,
        { contacted: true },
        { preserveScroll: true, onFinish: () => (markingContacted.value = null) },
    );
}
</script>

<template>
    <AppLayout :title="t('Reorder radar')">
        <div class="space-y-5">
            <PageHeader :title="t('Reorder radar')" :description="t('Active customers overdue to reorder, ranked by value-weighted urgency.')" />

            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="t('Due')" :value="formatNumber(radar.counts.due, locale)" />
                <StatCard :label="t('Overdue')" :value="formatNumber(radar.counts.overdue, locale)" alert />
                <StatCard :label="t('At risk')" :value="formatNumber(radar.counts.at_risk, locale)" alert />
            </div>

            <div class="max-w-sm">
                <Input v-model="filter" type="search" :placeholder="t('Search company…')" />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[56rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Customer') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Status') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Last order') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Usual gap') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Avg. order value') }}</th>
                                <th v-if="can('customers.manage')" scope="col" class="px-4 py-2.5 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.customer_id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <a :href="`/customers/${row.customer_id}`" class="font-medium text-foreground hover:underline">
                                        {{ row.company_name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ daysAgo(row.days_since_last) }}</td>
                                <td class="px-4 py-3 text-muted-foreground tabular-nums">
                                    {{ t(':count days', { count: formatNumber(Math.round(row.median_gap_days), locale) }) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums text-foreground">
                                    {{ formatMoney(row.avg_order_value.minor, row.avg_order_value.currency) }}
                                </td>
                                <td v-if="can('customers.manage')" class="px-4 py-3 text-right">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :disabled="markingContacted === row.customer_id"
                                        @click="markContacted(row.customer_id)"
                                    >
                                        {{ t('Mark contacted') }}
                                    </Button>
                                </td>
                            </tr>

                            <tr v-if="rows.length === 0">
                                <td :colspan="can('customers.manage') ? 6 : 5" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ filter ? t('No accounts match that search.') : t('No accounts are due to reorder.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
