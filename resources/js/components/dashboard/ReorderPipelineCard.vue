<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { RotateCcw } from 'lucide-vue-next';

import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type { ReorderPipeline } from '@/types/dashboard';
import type { SharedProps } from '@/types';

/**
 * "Reorder pipeline" (Figma `208:5915`): the same churn radar the Customers
 * module already surfaces — see App\Queries\ReorderRadarQuery — read down to
 * card-sized. `total` is what the whole flagged list is worth if each account
 * reorders at its usual size, not just the three rows shown below it.
 *
 * @todo "View all" (Figma `208:5921`). The design links this to a full
 * reorder-radar screen; the Inertia app has no such page yet — the radar is
 * only reachable through the API today.
 */
const props = defineProps<{ pipeline: ReorderPipeline; currency: string }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const money = (minor: number) => formatMoney(minor, props.currency);

/**
 * "94 days ago", matching the design's wording (`208:10179`).
 *
 * `days_since_last` is a fraction (ReorderRadarQuery measures the gap in
 * whole days but the median it compares against is not an integer), so it is
 * rounded here rather than trusted as a whole-day count — an unrounded value
 * would print as "48,963" in a locale that uses a comma for the decimal, which
 * reads as forty-eight thousand days, not forty-nine.
 */
function daysAgo(days: number): string {
    const whole = Math.round(days);

    if (whole <= 0) return t('Today');
    if (whole === 1) return t('1 day ago');

    return t(':count days ago', { count: formatNumber(whole, page.props.locale) });
}
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <div class="flex items-center gap-1.5 text-sm font-semibold">
            <RotateCcw class="size-4 text-muted-foreground" :stroke-width="1.5" />
            {{ t('Reorder pipeline') }}
        </div>

        <p class="mt-3 text-2xl font-semibold tabular-nums">{{ money(pipeline.total.minor) }}</p>

        <ul v-if="pipeline.rows.length" class="mt-4 flex-1 divide-y divide-border">
            <li v-for="row in pipeline.rows" :key="row.customer_id" class="flex items-center justify-between gap-3 py-2.5 first:pt-0">
                <div class="min-w-0">
                    <p class="truncate text-xs font-medium text-foreground">{{ row.company_name }}</p>
                    <p class="text-2xs text-muted-foreground">{{ daysAgo(row.days_since_last) }}</p>
                </div>
                <span class="shrink-0 text-xs tabular-nums">{{ money(row.avg_order_value.minor) }}</span>
            </li>
        </ul>
        <p v-else class="mt-4 flex-1 text-xs text-muted-foreground">{{ t('No accounts are due to reorder.') }}</p>
    </div>
</template>
