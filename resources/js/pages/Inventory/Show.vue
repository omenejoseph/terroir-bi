<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Download, PencilLine, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import AnalysisPanel from '@/components/inventory/AnalysisPanel.vue';
import DocsPanel from '@/components/inventory/DocsPanel.vue';
import ImagesGallery from '@/components/inventory/ImagesGallery.vue';
import ItemCustomerPriceDialog from '@/components/inventory/ItemCustomerPriceDialog.vue';
import ItemDetailFields from '@/components/inventory/ItemDetailFields.vue';
import ItemFormPanel from '@/components/inventory/ItemFormPanel.vue';
import ProduceForm from '@/components/inventory/ProduceForm.vue';
import QuickStockEntry from '@/components/inventory/QuickStockEntry.vue';
import RecipeEditor from '@/components/inventory/RecipeEditor.vue';
import SparkBars from '@/components/inventory/SparkBars.vue';
import StockRangeBar from '@/components/inventory/StockRangeBar.vue';
import TierPriceDialog from '@/components/inventory/TierPriceDialog.vue';
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
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber, formatQuantity } from '@/lib/money';
import { channelLabel, formatMovementDate, MOVEMENT_GROUPS, movementTypeLabel } from '@/lib/stock';
import type { PricingTierSummary } from '@/types/customers';
import type {
    BottleAnalysisRow,
    InventoryFileItem,
    InventoryImageItem,
    InventoryItem,
    ItemCustomerPriceRow,
    ItemPricing,
    ItemTierPriceRow,
    MoneyValue,
    RecipeInputOption,
    RecipeLine,
} from '@/types/inventory';
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
    pricing: ItemPricing;
    /** Every tenant pricing tier — only present once "Add tier price" has opened. */
    pricingTierOptions?: PricingTierSummary[];
    /** Every active customer — only present once "Add customer price" has opened. */
    pricingCustomerOptions?: { id: string; company_name: string }[];
    recipe: RecipeLine[];
    /** Every other active catalog item — only present once the Recipe tab has asked for it. */
    recipeInputOptions?: RecipeInputOption[];
    images: InventoryImageItem[];
    techSheets: InventoryFileItem[];
    documents: InventoryFileItem[];
    bottleAnalyses: BottleAnalysisRow[];
}>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const money = (m: MoneyValue | null) => (m ? formatMoney(m.minor, m.currency) : '—');
const num = (n: number) => formatNumber(n, locale.value);
const qty = (q: string | null) => formatQuantity(q, locale.value);

const current = computed(() => props.analytics.current);
const exits = computed(() => props.analytics.exits);
const realized = computed(() => props.analytics.realized);

/** The design's tab strip (Figma 449:1577). All seven tabs are built. */
const DETAIL_TABS: TabItem[] = [
    { label: t('Stock'), value: 'stock' },
    { label: t('Details'), value: 'details' },
    { label: t('Recipe'), value: 'recipe' },
    { label: t('Produce'), value: 'produce' },
    { label: t('Images'), value: 'images' },
    { label: t('Docs'), value: 'docs' },
    { label: t('Pricing'), value: 'pricing' },
    { label: t('Analysis'), value: 'analysis' },
];

/** Which of DETAIL_TABS is showing. Client-side only — no route change. */
const activeDetailTab = ref('stock');

/** The Details tab's "Edit" opens the same form the Inventory list uses. */
const itemFormOpen = ref(false);

/* ---- Pricing tab: add / edit / remove this item's tier and customer prices ---- */

const tierPriceDialogOpen = ref(false);
const editingTierPrice = ref<ItemTierPriceRow | null>(null);

function openAddTierPrice(): void {
    editingTierPrice.value = null;
    tierPriceDialogOpen.value = true;
}

function openEditTierPrice(row: ItemTierPriceRow): void {
    editingTierPrice.value = row;
    tierPriceDialogOpen.value = true;
}

async function removeTierPrice(row: ItemTierPriceRow): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove tier price'),
        description: t('Remove the :tier tier price for this item? That tier will pay list price instead.', {
            tier: row.tier_name ?? '—',
        }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/inventory/${props.item.id}/tier-prices/${row.pricing_tier_id}`, {
        preserveScroll: true,
        only: ['pricing'],
    });
}

const customerPriceDialogOpen = ref(false);
const editingCustomerPrice = ref<ItemCustomerPriceRow | null>(null);

function openAddCustomerPrice(): void {
    editingCustomerPrice.value = null;
    customerPriceDialogOpen.value = true;
}

function openEditCustomerPrice(row: ItemCustomerPriceRow): void {
    editingCustomerPrice.value = row;
    customerPriceDialogOpen.value = true;
}

async function removeCustomerPrice(row: ItemCustomerPriceRow): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove customer price'),
        description: t('Remove the price set for :name? They will pay the tier/list price instead.', {
            name: row.company_name ?? '—',
        }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/customers/${row.customer_id}/prices/${props.item.id}`, {
        preserveScroll: true,
        only: ['pricing'],
    });
}

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

function duplicate(): void {
    form.post(`/inventory/${props.item.id}/duplicate`);
}

async function destroy(): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete item'),
        description: t('Delete :name? Items referenced by orders are deactivated instead.', { name: props.item.name }),
        tone: 'danger',
    });
    if (!ok) return;

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

            <Tabs :items="DETAIL_TABS" :current="activeDetailTab" variant="segmented" @select="activeDetailTab = $event" />

            <template v-if="activeDetailTab === 'stock'">
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
                                <Button variant="outline" size="sm" :href="`/inventory/${item.id}/movements/export`" download>
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
            </template>

            <template v-else-if="activeDetailTab === 'details'">
                <Card>
                    <CardContent class="p-6">
                        <ItemDetailFields :item="item" @edit="itemFormOpen = true" />
                    </CardContent>
                </Card>
            </template>

            <template v-else-if="activeDetailTab === 'recipe'">
                <Card>
                    <CardContent class="p-6">
                        <RecipeEditor :item="item" :lines="recipe" :inputs="recipeInputOptions" />
                    </CardContent>
                </Card>
            </template>

            <template v-else-if="activeDetailTab === 'produce'">
                <Card>
                    <CardContent class="p-6">
                        <ProduceForm :item="item" :lines="recipe" />
                    </CardContent>
                </Card>
            </template>

            <template v-else-if="activeDetailTab === 'images'">
                <Card>
                    <CardContent class="p-6">
                        <ImagesGallery :item="item" :images="images" />
                    </CardContent>
                </Card>
            </template>

            <template v-else-if="activeDetailTab === 'docs'">
                <Card>
                    <CardContent class="p-6">
                        <DocsPanel :item="item" :tech-sheets="techSheets" :documents="documents" />
                    </CardContent>
                </Card>
            </template>

            <template v-else-if="activeDetailTab === 'pricing'">
                <div class="flex flex-col gap-5">
                    <!-- Tier prices -->
                    <div class="overflow-hidden border border-border bg-card">
                        <div class="flex items-start justify-between gap-3 border-b border-border px-6 py-4">
                            <SectionHeader
                                :title="t('Tier prices')"
                                :description="t('What each pricing tier pays for this item; anything not listed pays list price.')"
                            />
                            <Button v-if="can('pricing.manage')" size="sm" @click="openAddTierPrice">
                                <Plus class="size-3.5" :stroke-width="1.5" />
                                {{ t('Add tier price') }}
                            </Button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-xs">
                                <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Tier') }}</th>
                                        <th scope="col" class="px-6 py-2.5 text-right font-medium">{{ t('Price') }}</th>
                                        <th v-if="can('pricing.manage')" scope="col" class="px-6 py-2.5 font-medium">
                                            <span class="sr-only">{{ t('Actions') }}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in pricing.tiers"
                                        :key="row.pricing_tier_id"
                                        class="border-b border-border last:border-b-0"
                                    >
                                        <td class="px-6 py-3">
                                            <span class="font-medium">{{ row.tier_name ?? '—' }}</span>
                                            <span v-if="row.rebate_percent" class="mt-0.5 block text-muted-foreground">
                                                {{ t(':percent% rebate on everything else', { percent: row.rebate_percent }) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-right font-semibold tabular-nums">{{ money(row.price) }}</td>
                                        <td v-if="can('pricing.manage')" class="px-6 py-3">
                                            <div class="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    class="p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                                                    :aria-label="t('Edit price for :name', { name: row.tier_name ?? '—' })"
                                                    @click="openEditTierPrice(row)"
                                                >
                                                    <PencilLine class="size-3.5" :stroke-width="1.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                                                    :aria-label="t('Remove price for :name', { name: row.tier_name ?? '—' })"
                                                    @click="removeTierPrice(row)"
                                                >
                                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="pricing.tiers.length === 0">
                                        <td :colspan="can('pricing.manage') ? 3 : 2" class="px-6 py-12 text-center text-muted-foreground">
                                            {{ t('No tier prices set — every tier pays list price.') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Customer overrides -->
                    <div class="overflow-hidden border border-border bg-card">
                        <div class="flex items-start justify-between gap-3 border-b border-border px-6 py-4">
                            <SectionHeader
                                :title="t('Customer overrides')"
                                :description="t('Customers with their own negotiated price for this item, overriding tier and list price.')"
                            />
                            <Button v-if="can('pricing.manage')" size="sm" @click="openAddCustomerPrice">
                                <Plus class="size-3.5" :stroke-width="1.5" />
                                {{ t('Add customer price') }}
                            </Button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-xs">
                                <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Customer') }}</th>
                                        <th scope="col" class="px-6 py-2.5 text-right font-medium">{{ t('Price') }}</th>
                                        <th v-if="can('pricing.manage')" scope="col" class="px-6 py-2.5 font-medium">
                                            <span class="sr-only">{{ t('Actions') }}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in pricing.customers"
                                        :key="row.customer_id"
                                        class="border-b border-border last:border-b-0"
                                    >
                                        <td class="px-6 py-3 font-medium">{{ row.company_name ?? '—' }}</td>
                                        <td class="px-6 py-3 text-right font-semibold tabular-nums">{{ money(row.price) }}</td>
                                        <td v-if="can('pricing.manage')" class="px-6 py-3">
                                            <div class="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    class="p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                                                    :aria-label="t('Edit price for :name', { name: row.company_name ?? '—' })"
                                                    @click="openEditCustomerPrice(row)"
                                                >
                                                    <PencilLine class="size-3.5" :stroke-width="1.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                                                    :aria-label="t('Remove price for :name', { name: row.company_name ?? '—' })"
                                                    @click="removeCustomerPrice(row)"
                                                >
                                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="pricing.customers.length === 0">
                                        <td :colspan="can('pricing.manage') ? 3 : 2" class="px-6 py-12 text-center text-muted-foreground">
                                            {{ t('No negotiated prices yet for this item.') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>

            <template v-else-if="activeDetailTab === 'analysis'">
                <Card>
                    <CardContent class="p-6">
                        <AnalysisPanel :item="item" :analyses="bottleAnalyses" />
                    </CardContent>
                </Card>
            </template>
        </div>

        <ItemFormPanel :open="itemFormOpen" :item="item" :reload-only="['item']" @close="itemFormOpen = false" />

        <TierPriceDialog
            v-if="can('pricing.manage')"
            :open="tierPriceDialogOpen"
            :item-id="item.id"
            :tiers="pricingTierOptions"
            :editing="editingTierPrice"
            @close="tierPriceDialogOpen = false"
        />

        <ItemCustomerPriceDialog
            v-if="can('pricing.manage')"
            :open="customerPriceDialogOpen"
            :item-id="item.id"
            :customers="pricingCustomerOptions"
            :editing="editingCustomerPrice"
            @close="customerPriceDialogOpen = false"
        />
    </AppLayout>
</template>
