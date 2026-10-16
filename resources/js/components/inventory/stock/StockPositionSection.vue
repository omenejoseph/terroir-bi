<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Activity, Clock, Package } from 'lucide-vue-next';

import SectionLabel from '@/components/inventory/stock/SectionLabel.vue';
import StockRangeBar from '@/components/inventory/StockRangeBar.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import { moneyOf, type StockEconomics } from '@/lib/stockEconomics';
import type { MoneyValue } from '@/types/inventory';
import type { StockAnalytics, StockMovement } from '@/types/stock';
import type { SharedProps } from '@/types';

/**
 * STOCK POSITION: what is attention-worthy (chips), then the four headline cards — On hand, Cover,
 * Value on hand and Realised margin. Every figure comes from the same analytics payload as the
 * rest of the tab; the derived ones (potential profit, list margin) come from `stockEconomics`.
 */
const props = defineProps<{
    analytics: StockAnalytics;
    economics: StockEconomics;
    movements: StockMovement[];
    period: string;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const num = (n: number) => formatNumber(n, locale.value);
const money = (m: MoneyValue | null) => (m ? formatMoney(m.minor, m.currency) : '—');
const pct = (n: number | null) =>
    n === null ? '—' : `${n.toLocaleString(locale.value, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;

const current = computed(() => props.analytics.current);
const exits = computed(() => props.analytics.exits);
const min = computed(() => current.value.min_stock_bottles ?? 0);

const cases = computed(() =>
    current.value.bottles_per_case > 0 ? Math.floor(current.value.stock_bottles / current.value.bottles_per_case) : null,
);

/** Days of cover at this period's rate, to one decimal as the design shows it. */
const coverDays = computed(() => {
    const velocity = Number.parseFloat(exits.value.velocity_per_day);

    return velocity > 0 && current.value.stock_bottles > 0 ? current.value.stock_bottles / velocity : null;
});

const velocityText = computed(() =>
    Number.parseFloat(exits.value.velocity_per_day).toLocaleString(locale.value, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }),
);

/** The window the exits are over, as the design words it ("this month's exits"). */
const periodPhrase = computed(
    () =>
        ({
            today: t("today's"),
            mtd: t("this month's"),
            ytd: t("this year's"),
            '30d': t("the last 30 days'"),
            '90d': t("the last 90 days'"),
        })[props.period] ?? t("this period's"),
);

/** Whole euros, as the design rounds the Value on hand lines. */
const moneyWhole = (minor: number | null) =>
    minor === null ? '—' : formatMoney(Math.round(minor / 100) * 100, props.economics.currency).replace(/[.,]00(?=\D*$)/, '');

/* ---- attention chips ---------------------------------------------------- */

const chips = computed(() => {
    const out: { key: string; icon: typeof Package; text: string; alert?: boolean }[] = [];

    if (min.value > 0) {
        const diff = current.value.stock_bottles - min.value;

        out.push({
            key: 'minimum',
            icon: Package,
            alert: diff < 0,
            text:
                diff < 0
                    ? t(':count bottles below minimum', { count: num(Math.abs(diff)) })
                    : t(':count bottles above minimum', { count: num(diff) }),
        });
    }

    const internal = props.analytics.channels_by_customer.rows.find((row) => row.key === 'internal');

    if (internal) {
        out.push({
            key: 'internal',
            icon: Clock,
            text: t('Internal / POS is :pct % of :period exits — excluded from margin', {
                pct: Math.round(internal.share * 100),
                period: periodPhrase.value,
            }),
        });
    }

    const corrections = props.movements.filter((m) => m.is_reconciliation);
    const latest = corrections[0];

    if (latest) {
        const date = latest.created_at
            ? new Date(latest.created_at).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' })
            : '—';
        const amount = Number.parseFloat(latest.quantity);
        const signed = `${amount > 0 ? '+' : ''}${num(amount)}`;
        const more = corrections.length - 1;

        out.push({
            key: 'corrections',
            icon: Activity,
            text:
                t('1 inventory correction on :date (:amount)', { date, amount: signed }) +
                (more > 0 ? ` +${more}` : ''),
        });
    }

    return out;
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <div v-if="chips.length" class="flex flex-wrap gap-2">
            <span
                v-for="chip in chips"
                :key="chip.key"
                class="inline-flex items-center gap-2 border bg-card px-3 py-2 text-xs"
                :class="chip.alert ? 'border-destructive text-destructive' : 'border-border text-foreground'"
            >
                <component :is="chip.icon" class="size-3.5 shrink-0" :stroke-width="1.5" aria-hidden="true" />
                {{ chip.text }}
            </span>
        </div>

        <SectionLabel>{{ t('Stock position') }}</SectionLabel>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <!-- On hand -->
            <div class="flex flex-col gap-3 border border-border bg-card p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs text-muted-foreground">{{ t('On hand') }}</p>
                    <span v-if="cases !== null" class="bg-muted px-2 py-0.5 text-2xs text-muted-foreground">
                        {{ t(':count cases', { count: num(cases) }) }}
                    </span>
                </div>
                <p class="flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tabular-nums">{{ num(current.stock_bottles) }}</span>
                    <span class="text-sm text-muted-foreground">{{ current.unit }}</span>
                </p>
                <StockRangeBar :stock="current.stock_bottles" :min="min" :unit="current.unit" />
            </div>

            <!-- Cover -->
            <div class="flex flex-col gap-1 border border-border bg-card p-5">
                <p class="text-xs text-muted-foreground">{{ t('Cover') }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">
                    {{ coverDays !== null ? `${coverDays.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} d` : '—' }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">{{ t('at the current exit rate') }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ t(':rate :unit / day', { rate: velocityText, unit: current.unit }) }}
                </p>
            </div>

            <!-- Value on hand -->
            <div class="flex flex-col gap-1 border border-border bg-card p-5">
                <p class="text-xs text-muted-foreground">{{ t('Value on hand') }}</p>
                <p class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tabular-nums">{{ moneyWhole(economics.valueAtCost) }}</span>
                    <span class="text-sm text-muted-foreground">{{ t('at cost') }}</span>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ moneyWhole(economics.salesValue) }} {{ t('at realised price') }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ moneyWhole(economics.potentialProfit) }} {{ t('potential profit') }}
                </p>
            </div>

            <!-- Realised margin -->
            <div class="flex flex-col gap-1 border border-border bg-card p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs text-muted-foreground">{{ t('Realised margin') }}</p>
                    <span class="bg-muted px-2 py-0.5 text-2xs text-muted-foreground">{{ t('12 m') }}</span>
                </div>
                <p class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tabular-nums">{{ pct(economics.realisedMarginPct) }}</span>
                    <span v-if="economics.realisedMargin !== null" class="text-xs text-muted-foreground">
                        {{ money(moneyOf(economics.realisedMargin, economics.currency)) }}/{{ t('bottle') }}
                    </span>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">{{ t('list margin :pct', { pct: pct(economics.listMarginPct) }) }}</p>
                <p class="text-xs text-muted-foreground">{{ t('mean realised rebate :pct', { pct: pct(economics.rebatePct) }) }}</p>
            </div>
        </div>
    </section>
</template>
