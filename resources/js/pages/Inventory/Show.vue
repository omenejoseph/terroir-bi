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
    { label: 'Stock', value: 'stock' },
    { label: 'Details' },
    { label: 'Recipe' },
    { label: 'Produce' },
    { label: 'Images' },
    { label: 'Docs' },
    { label: 'Pricing' },
    { label: 'Analysis' },
];

/** The design's exit-period strip; these are the tokens the query accepts. */
const PERIOD_TABS: TabItem[] = [
    { value: 'today', label: 'Today' },
    { value: 'mtd', label: 'MTD' },
    { value: 'ytd', label: 'YTD' },
    { value: '30d', label: '30d' },
    { value: '90d', label: '90d' },
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
    if (!confirm(`Delete ${props.item.name}? Items referenced by orders are deactivated instead.`)) return;

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
                            aria-label="Back to inventory"
                        >
                            <ArrowLeft class="size-5" :stroke-width="1.5" />
                        </Link>
                        <h2 class="truncate text-3xl font-semibold tracking-tight text-foreground">{{ item.name }}</h2>
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
                    <Button variant="outline" size="sm" @click="duplicate">Duplicate</Button>
                    <Button v-if="can('inventory.delete')" variant="outline" size="sm" @click="destroy">
                        <Trash2 class="size-4 text-destructive" :stroke-width="1.5" />
                        <span class="text-destructive">Delete</span>
                    </Button>
                </div>
            </div>

            <Tabs :items="DETAIL_TABS" current="Stock" variant="segmented" />

            <!-- Stat tiles -->
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="On hand"
                    :value="num(current.stock_bottles)"
                    :hint="
                        [
                            current.unit,
                            cases !== null ? `${num(cases)} cases` : null,
                            current.min_stock_bottles > 0 ? `min ${num(current.min_stock_bottles)}` : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    "
                />
                <StatCard
                    label="Cover"
                    :value="exits.days_of_stock_left !== null ? `${exits.days_of_stock_left} d` : '—'"
                    hint="at this period's exit rate"
                />
                <StatCard label="Value on hand" :value="money(valueOnHand)" hint="at cost" />
                <StatCard
                    label="Realised margin"
                    :value="realized.margin_percent !== null ? `${realized.margin_percent} %` : '—'"
                    :hint="realized.bottles_sold > 0 ? `12 months · ${num(realized.bottles_sold)} sold` : '12 months'"
                />
            </section>

            <!-- Current stock -->
            <Card>
                <CardContent class="flex flex-col gap-5 p-6">
                    <SectionHeader
                        title="Current stock"
                        description="What is in the warehouse, what it cost, and what it is worth once it sells."
                    />

                    <div class="flex items-baseline gap-3">
                        <span class="text-3xl font-semibold tabular-nums">{{ num(current.stock_bottles) }}</span>
                        <span class="text-sm text-muted-foreground">{{ current.unit }}</span>
                        <span v-if="cases !== null" class="text-sm text-muted-foreground">{{ num(cases) }} cases</span>
                    </div>

                    <StockRangeBar
                        :stock="current.stock_bottles"
                        :min="current.min_stock_bottles"
                        :unit="current.unit"
                    />

                    <Separator />

                    <FormSection label="Cost basis">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-13 text-muted-foreground">Cost per unit</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(current.cost_per_bottle) }}</p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Value on hand</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(valueOnHand) }}</p>
                            </div>
                        </div>
                    </FormSection>

                    <Separator />

                    <FormSection label="Selling">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p class="text-13 text-muted-foreground">List price</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(current.selling_per_bottle) }}</p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Mean price (realised, 12m)</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(realized.mean_price) }}</p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Margin (realised, 12m)</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ realized.margin_percent !== null ? `${realized.margin_percent} %` : '—' }}
                                    <span v-if="realized.margin_amount" class="font-normal text-muted-foreground">
                                        {{ money(realized.margin_amount) }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Mean realised rebate</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ realized.rebate_percent !== null ? `${realized.rebate_percent} %` : '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Sales value (at realised price)</p>
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
                            title="Inventory spend"
                            description="Warehouse exit — every unit, regardless of channel."
                        >
                            <template #actions>
                                <Tabs
                                    :items="PERIOD_TABS"
                                    :current="filters.period"
                                    variant="segmented"
                                    @select="selectPeriod"
                                />
                            </template>
                        </SectionHeader>

                        <div class="flex items-baseline gap-3">
                            <span class="text-3xl font-semibold tabular-nums">{{ num(exits.bottles_exited) }}</span>
                            <span class="text-sm text-muted-foreground">{{ current.unit }} exited</span>
                            <span class="text-13 text-muted-foreground">
                                · {{ num(exits.movements_count) }} movements
                            </span>
                        </div>

                        <SparkBars :values="exits.spark" :unit="current.unit" />

                        <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-5">
                            <div>
                                <p class="text-13 text-muted-foreground">Cost of exits</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(exits.cost_of_exits) }}</p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Revenue (realised)</p>
                                <p class="mt-0.5 font-semibold tabular-nums">{{ money(exits.revenue_realized) }}</p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Mean margin</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ exits.mean_margin_percent !== null ? `${exits.mean_margin_percent} %` : '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Velocity</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ exits.velocity_per_day }}
                                    <span class="text-13 font-normal text-muted-foreground">
                                        {{ current.unit }}/day
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-13 text-muted-foreground">Days of stock left</p>
                                <p class="mt-0.5 font-semibold tabular-nums">
                                    {{ exits.days_of_stock_left !== null ? `${exits.days_of_stock_left} d` : '—' }}
                                </p>
                            </div>
                        </div>

                        <p v-if="exits.internal" class="text-13 text-muted-foreground">
                            of which Internal / POS: {{ num(exits.internal.bottles) }} {{ current.unit }} · cost
                            {{ money(exits.internal.cost) }} · revenue {{ money(exits.internal.revenue) }}
                            (excluded from margin)
                        </p>
                    </CardContent>
                </Card>

                <!-- Exit by channel -->
                <Card>
                    <CardContent class="flex flex-col gap-4 p-6">
                        <SectionHeader title="Exit by channel" description="Where the units went this period." />

                        <ul v-if="analytics.channels.length" class="flex flex-col gap-4">
                            <li v-for="channel in analytics.channels" :key="channel.channel" class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium">{{ channelLabel(channel.channel) }}</span>
                                    <span class="shrink-0 text-sm tabular-nums">{{ num(channel.bottles) }}</span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-foreground/70"
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
                        <p v-else class="py-6 text-sm text-muted-foreground">No exits in this period.</p>

                        <template v-if="analytics.channels.length">
                            <Separator />
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm font-medium">Total exited</span>
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
                    <SectionHeader title="Quick stock entry" />
                    <QuickStockEntry :item-id="item.id" :unit="current.unit" />
                </CardContent>
            </Card>

            <!-- Movement history -->
            <Card>
                <CardContent class="flex flex-col gap-4 p-6">
                    <SectionHeader
                        title="Movement history"
                        :description="`${movements.length} movements · running balance so you can see stock rebuild then drain`"
                    >
                        <template #actions>
                            <!-- @todo Export — needs a CSV endpoint for the ledger. -->
                            <Button variant="outline" size="sm">
                                <Download class="size-4" :stroke-width="1.5" />
                                Export
                            </Button>
                        </template>
                    </SectionHeader>

                    <Tabs
                        :items="MOVEMENT_TABS"
                        :current="movementFilter"
                        variant="segmented"
                        class="self-start"
                        @select="movementFilter = $event"
                    />

                    <div v-if="visibleMovements.length" class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-sm">
                            <thead class="border-b border-border text-left text-13 text-muted-foreground">
                                <tr>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Date</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Type</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">Quantity</th>
                                    <th scope="col" class="py-2.5 pr-4 text-right font-medium">Balance</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Reference</th>
                                    <th scope="col" class="py-2.5 pr-4 font-medium">Note</th>
                                    <th scope="col" class="py-2.5 font-medium">By</th>
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
                                            <Badge v-if="movement.is_reconciliation">correction</Badge>
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
                        {{ movements.length ? 'No movements of this kind.' : 'No stock movements recorded yet.' }}
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
