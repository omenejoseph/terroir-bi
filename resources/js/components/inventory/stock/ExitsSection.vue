<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import SectionLabel from '@/components/inventory/stock/SectionLabel.vue';
import SparkBars from '@/components/inventory/SparkBars.vue';
import { useTranslations } from '@/composables/useTranslations';
import { useVisitPending } from '@/composables/useVisitPending';
import { formatMoney, formatNumber } from '@/lib/money';
import { customerChannelLabel } from '@/lib/stock';
import type { MoneyValue } from '@/types/inventory';
import type { StockAnalytics } from '@/types/stock';
import type { SharedProps, } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * EXITS: the period selector, the Inventory spend card (histogram — Internal / POS bottles drawn
 * dark — and KPIs) and Exit by channel (customer channels, with revenue, healed against live orders).
 */
const props = defineProps<{ itemId: string; analytics: StockAnalytics; period: string }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const num = (n: number) => formatNumber(n, locale.value);
const money = (m: MoneyValue | null) => (m ? formatMoney(m.minor, m.currency) : '—');

const current = computed(() => props.analytics.current);
const exits = computed(() => props.analytics.exits);
const byCustomer = computed(() => props.analytics.channels_by_customer);

/** The design's exit-period strip; these are the tokens the query accepts. */
const PERIOD_TABS = computed<TabItem[]>(() => [
    { value: 'today', label: t('Today') },
    { value: 'mtd', label: t('MTD') },
    { value: 'ytd', label: t('YTD') },
    { value: '30d', label: t('30d') },
    { value: '90d', label: t('90d') },
]);

/** The strip answers at once; the figures it is about to replace dim until the partial response lands. */
const pending = useVisitPending();
const chosen = ref<string | null>(null);

function selectPeriod(period: string): void {
    chosen.value = period;
    // Only the period-dependent figures are refetched; the movements and the rest of the page stay as they are.
    router.get(`/inventory/${props.itemId}`, { period }, { preserveState: true, preserveScroll: true, replace: true, only: ['analytics', 'filters', 'vintageCoverage'], onFinish: () => (chosen.value = null) });
}

/** Bars fade down the list, as the design draws them: first channel full strength. */
const BAR_TONES = ['bg-foreground', 'bg-muted-foreground', 'bg-muted-foreground/40'];

/** Each bar's date (or date range), for its tooltip. */
const barLabels = computed(() => {
    const start = new Date(`${byCustomer.value.spark_start}T00:00:00`);
    const step = byCustomer.value.spark_step_days;
    const fmt = (d: Date) => d.toLocaleDateString(locale.value, { day: 'numeric', month: 'short' });

    return byCustomer.value.spark.map((_, i) => {
        const from = new Date(start.getTime() + i * step * 86_400_000);

        if (step <= 1) return fmt(from);

        const to = new Date(from.getTime() + (step - 1) * 86_400_000);

        return `${fmt(from)} – ${fmt(to)}`;
    });
});

/** What a bar is, in words: one day, or a run of days when the window is long. */
const barCaption = computed(() => {
    const step = byCustomer.value.spark_step_days;

    return step <= 1
        ? t('One bar per day, in :unit exited. Dark = Internal / POS, light = every other channel.', { unit: current.value.unit })
        : t('One bar per :days days, in :unit exited. Dark = Internal / POS, light = every other channel.', {
              days: Number(step.toFixed(1)),
              unit: current.value.unit,
          });
});

/** Days of stock left at this period's rate, to one decimal as the design shows it. */
const daysLeft = computed(() => {
    const rate = Number.parseFloat(exits.value.velocity_per_day);

    return rate > 0 && current.value.stock_bottles > 0
        ? (current.value.stock_bottles / rate).toLocaleString(locale.value, { minimumFractionDigits: 1, maximumFractionDigits: 1 })
        : null;
});

const velocity = computed(() =>
    Number.parseFloat(exits.value.velocity_per_day).toLocaleString(locale.value, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }),
);
</script>

<template>
    <section class="flex flex-col gap-3">
        <SectionLabel>
            {{ t('Exits') }}
            <template #actions>
                <div class="flex">
                    <button
                        v-for="tab in PERIOD_TABS"
                        :key="tab.value"
                        type="button"
                        :aria-pressed="(chosen ?? period) === tab.value"
                        class="border px-2.5 py-1 text-xs transition-colors"
                        :class="
                            (chosen ?? period) === tab.value
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card text-foreground hover:border-foreground/40'
                        "
                        @click="selectPeriod(tab.value!)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </template>
        </SectionLabel>

        <div class="grid gap-4 transition-opacity duration-150 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]" :class="pending ? 'opacity-50 delay-150' : ''" :aria-busy="pending">
            <!-- Inventory spend -->
            <div class="flex flex-col gap-4 border border-border bg-card p-6">
                <div>
                    <h3 class="text-base font-semibold">{{ t('Inventory spend') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ t('Warehouse exit — every bottle, regardless of channel.') }}</p>
                </div>

                <p class="flex items-baseline gap-3">
                    <span class="text-4xl font-semibold tabular-nums">{{ num(exits.bottles_exited) }}</span>
                    <span class="text-sm text-muted-foreground">{{ current.unit }} {{ t('exited') }}</span>
                    <span class="text-xs text-muted-foreground">· {{ num(exits.movements_count) }} {{ t('movements') }}</span>
                </p>

                <SparkBars
                    :values="byCustomer.spark"
                    :highlight="byCustomer.spark_internal"
                    :labels="barLabels"
                    :highlight-label="t('Internal / POS')"
                    :rest-label="t('Other exits')"
                    :unit="current.unit"
                />
                <p class="-mt-2 text-2xs text-muted-foreground">{{ barCaption }}</p>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Cost of exits') }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ money(exits.cost_of_exits) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Revenue (realised)') }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ money(exits.revenue_realized) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Mean margin') }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums">
                            {{ exits.mean_margin_percent !== null ? `${exits.mean_margin_percent} %` : '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Velocity') }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums">
                            {{ velocity }}
                            <span class="text-xs font-normal text-muted-foreground">{{ t(':unit/day', { unit: current.unit }) }}</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Days of stock left') }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums">
                            {{ daysLeft !== null ? `${daysLeft} d` : '—' }}
                        </p>
                    </div>
                </div>

                <p v-if="exits.internal" class="border-t border-border pt-3 text-xs text-muted-foreground">
                    {{
                        t('of which Internal / POS: :bottles :unit · cost :cost · revenue :revenue (excluded from margin)', {
                            bottles: num(exits.internal.bottles),
                            unit: current.unit,
                            cost: money(exits.internal.cost),
                            revenue: money(exits.internal.revenue),
                        })
                    }}
                </p>
            </div>

            <!-- Exit by channel -->
            <div class="flex flex-col gap-4 border border-border bg-card p-6">
                <div>
                    <h3 class="text-base font-semibold">{{ t('Exit by channel') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ t('Where the bottles went this period.') }}</p>
                </div>

                <ul v-if="byCustomer.rows.length" class="flex flex-col gap-4">
                    <li v-for="(row, i) in byCustomer.rows" :key="row.key" class="flex flex-col gap-1.5">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="truncate text-sm font-medium">{{ customerChannelLabel(row.key) }}</span>
                            <span class="shrink-0 text-sm font-semibold tabular-nums">{{ num(row.bottles) }} btl</span>
                        </div>
                        <div class="h-1.5 w-full bg-muted">
                            <div class="h-full" :class="BAR_TONES[Math.min(i, BAR_TONES.length - 1)]" :style="{ width: `${row.share * 100}%` }" />
                        </div>
                        <p class="text-2xs text-muted-foreground tabular-nums">
                            {{ Math.round(row.share * 100) }} %<template v-if="row.revenue"> · {{ money(row.revenue) }}</template>
                        </p>
                    </li>
                </ul>
                <p v-else class="py-6 text-sm text-muted-foreground">{{ t('No exits in this period.') }}</p>

                <template v-if="byCustomer.rows.length">
                    <div class="flex items-baseline justify-between gap-3 border-t border-border pt-3">
                        <span class="text-sm font-medium">{{ t('Total exited') }}</span>
                        <span class="text-sm font-semibold tabular-nums">{{ num(byCustomer.total_bottles) }} {{ current.unit }}</span>
                    </div>
                    <p v-if="byCustomer.excluded_bottles > 0" class="text-xs text-muted-foreground">
                        {{ t('+:count bottles from edited / deleted orders excluded', { count: num(byCustomer.excluded_bottles) }) }}
                    </p>
                </template>
            </div>
        </div>
    </section>
</template>
