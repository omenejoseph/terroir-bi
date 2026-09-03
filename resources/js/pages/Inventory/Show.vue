<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Download, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import QuickStockEntry from '@/components/inventory/QuickStockEntry.vue';
import SparkBars from '@/components/inventory/SparkBars.vue';
import StockRangeBar from '@/components/inventory/StockRangeBar.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import FormSection from '@/components/ui/FormSection.vue';
import MetaStrip from '@/components/ui/MetaStrip.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber, formatQuantity } from '@/lib/money';
import { channelLabel, formatMovementDate, MOVEMENT_GROUPS, movementTypeLabel } from '@/lib/stock';
import type { InventoryItem, MoneyValue } from '@/types/inventory';
import type { StockAnalytics, StockMovement } from '@/types/stock';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * Product Detail (Figma `449:1577`).
 *
 * The design's tabs (Details, Recipe, Produce, Images, Docs, Pricing, Analysis)
 * are destinations that are not built yet, so they render disabled rather than
 * as dead links. Everything on the Stock tab is backed by
 * InventoryItemStockAnalyticsQuery.
 */
const props = defineProps<{
    item: InventoryItem;
    analytics: StockAnalytics;
    movements: StockMovement[];
    vintageCoverage: Record<string, unknown> | null;
    filters: { period: string };
}>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const money = (m: MoneyValue | null) => (m ? formatMoney(m.minor, m.currency, locale.value) : '—');
const num = (n: number) => formatNumber(n, locale.value);
const qty = (q: string | null) => formatQuantity(q, locale.value);

const current = computed(() => props.analytics.current);
const exits = computed(() => props.analytics.exits);
const realized = computed(() => props.analytics.realized);

/**
 * The design's tab strip (Figma 449:1577). Only Stock is built.
 *
 * @todo Details  — the item's own fields; the Item — View drawer already
 *                  renders them and could be lifted here.
 * @todo Recipe   — bill of materials. SetRecipeAction and the recipe endpoint
 *                  exist; this needs the editor.
 * @todo Produce  — ProduceItemAction exists; needs the run form.
 * @todo Images   — InventoryMediaController + presigned uploads exist; needs
 *                  the gallery and upload UI.
 * @todo Docs     — tech sheets and documents; attach endpoints exist.
 * @todo Pricing  — customer overrides and tiers; PriceController exists.
 * @todo Analysis — BottleAnalysisController exists; needs the results view.
 */
const DETAIL_TABS: TabItem[] = [
    { label: t('Stock'), value: 'stock' },
    { label: t('Details') },
    { label: t('Recipe') },
    { label: t('Produce') },
    { label: t('Images') },
    { label: t('Docs') },
    { label: t('Pricing') },
    { label: t('Analysis') },
];

/** The design's exit-period strip; these are the tokens the query accepts. */
const PERIOD_TABS: TabItem[] = [
    { value: 'today', label: t('Today') },
    { value: 'mtd', label: t('MTD') },
    { value: 'ytd', label: t('YTD') },
    { value: '30d', label: t('30d') },
    { value: '90d', label: t('90d') },
];

function selectPeriod(period: string): void {
    router.get(`/inventory/${props.item.id}`, { period }, { preserveState: true, preserveScroll: true });
}

/** Stock at cost — the design's "Value on hand". */
const valueOnHand = computed<MoneyValue | null>(() => {
    const cost = current.value.cost_per_bottle;

    return cost ? { ...cost, minor: Math.round(cost.minor * current.value.stock_bottles) } : null;
});

const cases = computed(() =>
    current.value.bottles_per_case > 0
        ? Math.floor(current.value.stock_bottles / current.value.bottles_per_case)
        : null,
);

const channelTotal = computed(() => props.analytics.channels.reduce((sum, c) => sum + c.bottles, 0));

/** Movement-history filter (Figma 449:1577: All / Orders / Produced / Adjustments). */
const movementFilter = ref('All');

const MOVEMENT_TABS: TabItem[] = Object.keys(MOVEMENT_GROUPS).map((label) => ({ label, value: label }));

const visibleMovements = computed(() => {
    const types = MOVEMENT_GROUPS[movementFilter.value];

    return types === null || types === undefined
        ? props.movements
        : props.movements.filter((m) => types.includes(m.type));
});

const form = useForm({});

/**
 * @todo Duplicate. DuplicateInventoryItemAction exists and the API exposes it;
 * this needs a web route before the button can call anything.
 */
function duplicate(): void {}

function destroy(): void {
    if (!confirm(t('Delete :name? Items referenced by orders are deactivated instead.', { name: props.item.name }))) return;

    form.delete(`/inventory/${props.item.id}`);
}
</script>

<template>
    <AppLayout :title="item.name">
        <div class="flex flex-col gap-5">
            <!-- Title row -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <Link
                            href="/inventory"
                            class="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            :aria-label="t('Back to inventory')"
                        >
                            <ArrowLeft class="size-5" :stroke-width="1.5" />
                        </Link>
                        <h2 class="truncate text-xl font-semibold text-foreground">{{ item.name }}</h2>
                    </div>
                    <MetaStrip
                        class="mt-1 pl-8"
                        :items="[
                            item.sku,
                            item.category,
                            item.vintage ? String(item.vintage) : null,
                            `${qty(item.current_stock)} ${current.unit}`,
                        ]"
                    />
                </div>

                <div v-if="can('inventory.manage')" class="flex shrink-0 items-center gap-2">
                    <Button variant="outline" size="sm" @click="duplicate">{{ t('Duplicate') }}</Button>
                    <Button v-if="can('inventory.delete')" variant="outline" size="sm" @click="destroy">
                        <Trash2 class="size-4 text-destructive" :stroke-width="1.5" />
                        <span class="text-destructive">{{ t('Delete') }}</span>
                    </Button>
                </div>
            </div>

            <Tabs :items="DETAIL_TABS" :current="t('Stock')" variant="segmented" />

            <!-- Stat tiles -->
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :label="t('On hand')"
                    :value="num(current.stock_bottles)"
                    :hint="
                        [
                            current.unit,
                            cases !== null ? t(':count cases', { count: num(cases) }) : null,
                            current.min_stock_bottles > 0 ? t('min :count', { count: num(current.min_stock_bottles) }) : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    "
                />
                <StatCard
                    :label="t('Cover')"
                    :value="exits.days_of_stock_left !== null ? `${exits.days_of_stock_left} d` : '—'"
                    :hint="t('at this period\'s exit rate')"
                />
                <StatCard :label="t('Value on hand')" :value="money(valueOnHand)" :hint="t('at cost')" />
                <StatCard
                    :label="t('Realised margin')"
                    :value="realized.margin_percent !== null ? `${realized.margin_percent} %` : '—'"
                    :hint="realized.bottles_sold > 0 ? t('12 months · :sold sold', { sold: num(realized.bottles_sold) }) : t('12 months')"
                />
            </section>

            <!-- Current stock -->
            <Card>
                <CardContent class="flex flex-col gap-5 p-6">
                    <SectionHeader
                        :title="t('Current stock')"
                        :description="t('What is in the warehouse, what it cost, and what it is worth once it sells.')"
                    />

                    <div class="flex items-baseline gap-3">
                        <span class="text-3xl font-semibold tabular-nums">{{ num(current.stock_bottles) }}</span>
                        <span class="text-sm text-muted-foreground">{{ current.unit }}</span>
                        <span v-if="cases !== null" class="text-sm text-muted-foreground">{{ t(':count cases', { count: num(cases) }) }}</span>
                    </div>

                    <StockRangeBar
                        :stock="current.stock_bottles"
                        :min="current.min_stock_bottles"
                        :unit="current.unit"
                    />

                    <Separator />

                    <FormSection :label="t('Cost basis')">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Cost per unit') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(current.cost_per_bottle) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Value on hand') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(valueOnHand) }}</p>
                            </div>
                        </div>
                    </FormSection>

                    <Separator />

                    <FormSection :label="t('Selling')">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('List price') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(current.selling_per_bottle) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Mean price (realised, 12m)') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(realized.mean_price) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Margin (realised, 12m)') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ realized.margin_percent !== null ? `${realized.margin_percent} %` : '—' }}
                                    <span v-if="realized.margin_amount" class="font-normal text-muted-foreground">
                                        {{ money(realized.margin_amount) }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Mean realised rebate') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ realized.rebate_percent !== null ? `${realized.rebate_percent} %` : '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Sales value (at realised price)') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(realized.sales_value) }}</p>
                            </div>
                        </div>
                    </FormSection>
                </CardContent>
            </Card>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <!-- Inventory spend -->
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader
                            :title="t('Inventory spend')"
                            :description="t('Warehouse exit — every unit, regardless of channel.')"
                        >
                            <template #actions>
                                <Tabs
                                    :items="PERIOD_TABS"
                                    :current="filters.period"
                                    variant="solid"
                                    @select="selectPeriod"
                                />
                            </template>
                        </SectionHeader>

                        <div class="flex items-baseline gap-3">
                            <span class="text-3xl font-semibold tabular-nums">{{ num(exits.bottles_exited) }}</span>
                            <span class="text-sm text-muted-foreground">{{ current.unit }} {{ t('exited') }}</span>
                            <span class="text-xs text-muted-foreground">
                                · {{ num(exits.movements_count) }} {{ t('movements') }}
                            </span>
                        </div>

                        <SparkBars :values="exits.spark" :unit="current.unit" />

                        <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-5">
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Cost of exits') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(exits.cost_of_exits) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Revenue (realised)') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(exits.revenue_realized) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Mean margin') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ exits.mean_margin_percent !== null ? `${exits.mean_margin_percent} %` : '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Velocity') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ exits.velocity_per_day }}
                                    <span class="text-xs font-normal text-muted-foreground">
                                        {{ t(':unit/day', { unit: current.unit }) }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">{{ t('Days of stock left') }}</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ exits.days_of_stock_left !== null ? `${exits.days_of_stock_left} d` : '—' }}
                                </p>
                            </div>
                        </div>

                        <p v-if="exits.internal" class="text-xs text-muted-foreground">
                            {{
                                t('of which Internal / POS: :bottles :unit · cost :cost · revenue :revenue (excluded from margin)', {
                                    bottles: num(exits.internal.bottles),
                                    unit: current.unit,
                                    cost: money(exits.internal.cost),
                                    revenue: money(exits.internal.revenue),
                                })
                            }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Exit by channel -->
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader :title="t('Exit by channel')" :description="t('Where the units went this period.')" />

                        <ul v-if="analytics.channels.length" class="flex flex-col gap-4">
                            <li v-for="channel in analytics.channels" :key="channel.channel" class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium">{{ channelLabel(channel.channel) }}</span>
                                    <span class="shrink-0 text-sm tabular-nums">{{ num(channel.bottles) }}</span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden bg-muted">
                                    <div
                                        class="h-full bg-foreground/70"
                                        :style="{
                                            width: `${channelTotal > 0 ? (channel.bottles / channelTotal) * 100 : 0}%`,
                                        }"
                                    />
                                </div>
                                <p class="text-2xs text-muted-foreground">
                                    {{ channelTotal > 0 ? Math.round((channel.bottles / channelTotal) * 100) : 0 }} %
                                </p>
                            </li>
                        </ul>
                        <p v-else class="py-6 text-sm text-muted-foreground">{{ t('No exits in this period.') }}</p>

                        <template v-if="analytics.channels.length">
                            <Separator />
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm font-medium">{{ t('Total exited') }}</span>
                                <span class="text-sm font-semibold tabular-nums">
                                    {{ num(channelTotal) }} {{ current.unit }}
                                </span>
                            </div>
                        </template>
                    </CardContent>
                </Card>
            </div>

            <!-- Quick stock entry -->
            <Card v-if="can('inventory.manage')">
                <CardContent class="flex flex-col gap-4 p-6">
                    <SectionHeader :title="t('Quick stock entry')" />
                    <QuickStockEntry :item-id="item.id" :unit="current.unit" />
                </CardContent>
            </Card>

            <!-- Movement history -->
            <Card>
                <CardContent class="flex flex-col gap-4 p-6">
                    <SectionHeader
                        :title="t('Movement history')"
                        :description="t(':count movements · running balance so you can see stock rebuild then drain', { count: movements.length })"
                    >
                        <template #actions>
                            <!-- @todo Export — needs a CSV endpoint for the ledger. -->
                            <Button variant="outline" size="sm">
                                <Download class="size-4" :stroke-width="1.5" />
                                {{ t('Export') }}
                            </Button>
                        </template>
                    </SectionHeader>

                    <Tabs
                        :items="MOVEMENT_TABS"
                        :current="movementFilter"
                        variant="solid"
                        class="self-start"
                        @select="movementFilter = $event"
                    />

                    <div v-if="visibleMovements.length" class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-sm">
                            <thead class="border-b border-border text-left text-3xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Date') }}</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Type') }}</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">{{ t('Quantity') }}</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">{{ t('Balance') }}</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Reference') }}</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Note') }}</th>
                                    <th scope="col" class="py-2.5 font-medium">{{ t('By') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="movement in visibleMovements" :key="movement.id">
                                    <td class="py-3 pr-4 whitespace-nowrap text-muted-foreground">
                                        {{ formatMovementDate(movement.created_at, locale) }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <Badge variant="outline">{{ movementTypeLabel(movement.type) }}</Badge>
                                            <Badge v-if="movement.is_reconciliation">{{ t('correction') }}</Badge>
                                        </div>
                                    </td>
                                    <td
                                        class="py-3 pr-4 text-right tabular-nums"
                                        :class="Number.parseFloat(movement.quantity) < 0 && 'text-destructive'"
                                    >
                                        {{ Number.parseFloat(movement.quantity) > 0 ? '+' : '' }}{{ qty(movement.quantity) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ qty(movement.balance) }}</td>
                                    <td class="py-3 pr-4 text-muted-foreground">{{ movement.reference ?? '—' }}</td>
                                    <td class="py-3 pr-4 text-muted-foreground">{{ movement.note ?? '—' }}</td>
                                    <td class="py-3 text-muted-foreground">{{ movement.created_by?.name ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-6 text-sm text-muted-foreground">
                        {{ movements.length ? t('No movements of this kind.') : t('No stock movements recorded yet.') }}
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
