<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import SectionLabel from '@/components/inventory/stock/SectionLabel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import { moneyOf, type StockEconomics } from '@/lib/stockEconomics';
import type { StockAnalytics, VintageCoverage } from '@/types/stock';
import type { SharedProps } from '@/types';

/**
 * ECONOMICS: Per bottle (realised price against list, and what the gap is made of), Whole lot (the
 * same story for everything on hand) and Vintage transition (this vintage against the previous one).
 * The figures come from `stockEconomics`; the vintage card renders VintageCoverageQuery.
 */
const props = defineProps<{
    analytics: StockAnalytics;
    economics: StockEconomics;
    vintageCoverage: VintageCoverage | null;
    itemName: string;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const num = (n: number) => formatNumber(n, locale.value);
const eco = computed(() => props.economics);
const money = (minor: number | null) => (minor === null ? '—' : formatMoney(minor, eco.value.currency));
const pct = (n: number | null) =>
    n === null ? '—' : `${n.toLocaleString(locale.value, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;
const unit = computed(() => props.analytics.current.unit);

/** A bar across 0 … `max`, as a percentage of the track. */
const share = (value: number | null, max: number | null): number =>
    value === null || max === null || max <= 0 ? 0 : Math.max(0, Math.min(100, (value / max) * 100));

/* ---- Per bottle: scale runs from 0 to the larger of list and realised price ---- */
const bottleScale = computed(() => Math.max(eco.value.listPerBottle ?? 0, eco.value.realisedPerBottle ?? 0));

/* ---- Whole lot: scale runs to sales value ---- */
const lotScale = computed(() => Math.max(eco.value.salesValue ?? 0, eco.value.valueAtCost ?? 0));

/* ---- Vintage transition ---- */
const vintages = computed(() => props.vintageCoverage?.vintages ?? []);
const maxVintageStock = computed(() => Math.max(1, ...vintages.value.map((v) => v.stock_bottles)));
const minStock = computed(() => props.analytics.current.min_stock_bottles ?? 0);

function vintageLabel(index: number, isCurrent: boolean, vintage: string | null): string {
    const name = vintage ?? t('NV');

    if (isCurrent) return t(':vintage (this)', { vintage: name });

    // The first one older than this vintage is "the previous"; further ones are just their year.
    const currentIndex = vintages.value.findIndex((v) => v.is_current);

    return index === currentIndex + 1 ? t(':vintage (previous)', { vintage: name }) : name;
}

const daysText = (days: number | null) =>
    days === null ? t('no recent exits') : t(':days d at current exit rate', { days: days.toLocaleString(locale.value, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) });
</script>

<template>
    <section class="flex flex-col gap-3">
        <SectionLabel>{{ t('Economics') }}</SectionLabel>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Per bottle -->
            <div class="flex flex-col gap-4 border border-border bg-card p-6">
                <div>
                    <h3 class="text-base font-semibold">{{ t('Per bottle') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ t('Realised, last 12 months.') }}</p>
                </div>

                <template v-if="eco.realisedPerBottle !== null">
                    <p class="flex items-baseline gap-2">
                        <span class="text-4xl font-semibold tabular-nums">{{ money(eco.realisedPerBottle) }}</span>
                        <span class="text-sm text-muted-foreground">{{ t('realised price') }}</span>
                        <span v-if="eco.listPerBottle !== null" class="text-xs text-muted-foreground">{{ t('list :price', { price: money(eco.listPerBottle) }) }}</span>
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <div class="relative flex h-2 w-full bg-muted">
                            <div class="h-full bg-foreground" :style="{ width: `${share(eco.costPerBottle, bottleScale)}%` }" />
                            <div class="h-full bg-muted-foreground" :style="{ width: `${share(eco.realisedMargin, bottleScale)}%` }" />
                            <span
                                class="absolute top-1/2 h-3.5 w-0.5 -translate-y-1/2 bg-foreground"
                                :style="{ left: `${share(eco.realisedPerBottle, bottleScale)}%` }"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="flex justify-between text-2xs text-muted-foreground tabular-nums">
                            <span>0</span>
                            <span>{{ (eco.realisedPerBottle / 100).toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
                            <span v-if="eco.listPerBottle !== null">{{ (eco.listPerBottle / 100).toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
                        </div>
                    </div>
                </template>
                <p v-else class="text-sm text-muted-foreground">{{ t('Nothing sold in the last 12 months, so there is no realised price yet.') }}</p>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-xs">
                    <div>
                        <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 bg-foreground" aria-hidden="true" />{{ t('Cost') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.costPerBottle) }}</dd>
                        <dd class="text-muted-foreground">{{ t(':pct of list', { pct: pct(eco.costPctOfList) }) }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 bg-muted-foreground" aria-hidden="true" />{{ t('Realised margin') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.realisedMargin) }}</dd>
                        <dd class="text-muted-foreground">{{ t(':pct of realised price', { pct: pct(eco.realisedMarginPct) }) }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 bg-muted" aria-hidden="true" />{{ t('Mean rebate') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.rebate) }}</dd>
                        <dd class="text-muted-foreground">{{ t(':pct off list', { pct: pct(eco.rebatePct) }) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">{{ t('List margin') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.listMargin) }}</dd>
                        <dd class="text-muted-foreground">{{ t(':pct — if sold at list', { pct: pct(eco.listMarginPct) }) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Whole lot -->
            <div class="flex flex-col gap-4 border border-border bg-card p-6">
                <div>
                    <h3 class="text-base font-semibold">{{ t('Whole lot') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t(':count :unit on hand, at realised price.', { count: num(eco.stockBottles), unit }) }}
                    </p>
                </div>

                <template v-if="eco.salesValue !== null">
                    <p class="flex items-baseline gap-2">
                        <span class="text-4xl font-semibold tabular-nums">{{ money(eco.salesValue) }}</span>
                        <span class="text-sm text-muted-foreground">{{ t('sales value') }}</span>
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <div class="flex h-2 w-full bg-muted">
                            <div class="h-full bg-foreground" :style="{ width: `${share(eco.valueAtCost, lotScale)}%` }" />
                            <div class="h-full bg-muted-foreground" :style="{ width: `${share(eco.potentialProfit, lotScale)}%` }" />
                        </div>
                        <div class="flex justify-between text-2xs text-muted-foreground tabular-nums">
                            <span>0</span>
                            <span v-if="eco.valueAtCost !== null">{{ Math.round(eco.valueAtCost / 100).toLocaleString(locale) }}</span>
                            <span>{{ Math.round(eco.salesValue / 100).toLocaleString(locale) }}</span>
                        </div>
                    </div>
                </template>
                <p v-else class="text-sm text-muted-foreground">{{ t('Nothing sold in the last 12 months, so there is no realised price yet.') }}</p>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-xs">
                    <div>
                        <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 bg-foreground" aria-hidden="true" />{{ t('Production value') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.valueAtCost) }}</dd>
                        <dd class="text-muted-foreground">{{ t('at cost · :cost /btl', { cost: money(eco.costPerBottle) }) }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 bg-muted-foreground" aria-hidden="true" />{{ t('Potential profit') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.potentialProfit) }}</dd>
                        <dd class="text-muted-foreground">{{ t(':pct of sales value', { pct: pct(eco.potentialProfitPct) }) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">{{ t('Sales value') }}</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums text-foreground">{{ money(eco.salesValue) }}</dd>
                        <dd class="text-muted-foreground">{{ t('at realised price · :price /btl', { price: money(eco.realisedPerBottle) }) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Vintage transition -->
            <div class="flex flex-col gap-4 border border-border bg-card p-6">
                <div>
                    <h3 class="text-base font-semibold">{{ t('Vintage transition') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t(':name across vintages · combined cover', { name: vintageCoverage?.wine_name ?? itemName }) }}
                    </p>
                </div>

                <template v-if="vintageCoverage">
                    <ul class="flex flex-col gap-4">
                        <li v-for="(v, i) in vintages" :key="v.inventory_item_id" class="flex flex-col gap-1.5">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm font-medium">{{ vintageLabel(i, v.is_current, v.vintage) }}</span>
                                <span class="text-sm font-semibold tabular-nums">{{ num(v.stock_bottles) }} btl</span>
                            </div>
                            <div class="h-1.5 w-full bg-muted">
                                <div class="h-full" :class="v.is_current ? 'bg-foreground' : 'bg-muted-foreground'" :style="{ width: `${share(v.stock_bottles, maxVintageStock)}%` }" />
                            </div>
                            <p class="text-2xs text-muted-foreground">
                                {{ daysText(v.days_left) }}<template v-if="v.is_current && minStock > 0"> · {{ t('min :count', { count: num(minStock) }) }}</template>
                            </p>
                        </li>
                    </ul>

                    <div class="flex items-baseline justify-between gap-3 border-t border-border pt-3">
                        <span class="text-sm font-medium">{{ t('Combined cover') }}</span>
                        <span class="text-sm font-semibold tabular-nums">
                            {{ vintageCoverage.combined_days_left !== null ? `${vintageCoverage.combined_days_left.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} d` : '—' }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ vintageCoverage.gap_risk ? t('Gap risk: the oldest vintage runs out in under 30 days and no newer one is in stock.') : t('Sell-through of the previous vintage before this one releases.') }}
                    </p>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('Only one vintage of :name is on file, so there is nothing to transition.', { name: itemName }) }}
                </p>
            </div>
        </div>
    </section>
</template>
