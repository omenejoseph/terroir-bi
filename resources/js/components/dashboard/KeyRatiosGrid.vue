<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import { formatMoney } from '@/lib/money';
import type { DashboardKeyRatios } from '@/types/dashboard';
import type { SharedProps } from '@/types';

/**
 * The bottom ratio grid (Figma `208:6303`): eight tiles in a 2-column, 4-row
 * layout, each a label over a value. `App\Services\Dashboard\DashboardSummary
 * ::keyRatios()` already computes every one of these — this component only
 * had to be written, not a new query.
 *
 * A null value renders "—" rather than a misleading 0%: the service returns
 * null specifically when the ratio's denominator isn't reliable yet (no
 * payroll imported, no shipped orders), and turning that into a rendered zero
 * would report something the data does not actually say.
 */
const props = defineProps<{ ratios: DashboardKeyRatios; currency: string }>();

const page = usePage<SharedProps>();

const money = (value: { minor: number } | null) =>
    value === null ? '—' : formatMoney(value.minor, props.currency, page.props.locale);

const pct = (value: number | null) => (value === null ? '—' : `${value.toLocaleString(page.props.locale)}%`);

const times = (value: number | null) => (value === null ? '—' : `${value.toLocaleString(page.props.locale)}×`);

const tiles = computed(() => [
    { label: 'DTC Revenue', value: pct(props.ratios.dtc_revenue_pct) },
    { label: 'Operating Margin', value: pct(props.ratios.operating_margin_pct) },
    { label: 'Employee Cost', value: pct(props.ratios.employee_cost_pct) },
    { label: 'Marketing Cost', value: pct(props.ratios.marketing_cost_pct) },
    { label: 'COGS', value: pct(props.ratios.cogs_pct) },
    { label: 'Revenue / Employee', value: money(props.ratios.revenue_per_employee) },
    { label: 'Avg Order Value', value: money(props.ratios.avg_order_value) },
    { label: 'Inventory Turnover', value: times(props.ratios.inventory_turnover) },
]);
</script>

<template>
    <div class="grid grid-cols-2 gap-px overflow-hidden border border-border bg-border">
        <div v-for="tile in tiles" :key="tile.label" class="bg-card p-4">
            <p class="text-xs text-muted-foreground">{{ tile.label }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ tile.value }}</p>
        </div>
    </div>
</template>
