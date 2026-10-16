<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, PencilLine, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import AnalysisPanel from '@/components/inventory/AnalysisPanel.vue';
import DocsPanel from '@/components/inventory/DocsPanel.vue';
import ImagesGallery from '@/components/inventory/ImagesGallery.vue';
import ItemCustomerPriceDialog from '@/components/inventory/ItemCustomerPriceDialog.vue';
import ItemDetailFields from '@/components/inventory/ItemDetailFields.vue';
import ItemFormPanel from '@/components/inventory/ItemFormPanel.vue';
import ProduceForm from '@/components/inventory/ProduceForm.vue';
import RecipeEditor from '@/components/inventory/RecipeEditor.vue';
import EconomicsSection from '@/components/inventory/stock/EconomicsSection.vue';
import ExitsSection from '@/components/inventory/stock/ExitsSection.vue';
import MovementsSection from '@/components/inventory/stock/MovementsSection.vue';
import StockPositionSection from '@/components/inventory/stock/StockPositionSection.vue';
import TierPriceDialog from '@/components/inventory/TierPriceDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import { listReturnUrl } from '@/lib/listReturn';
import { stockEconomics } from '@/lib/stockEconomics';
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
import type { StockAnalytics, StockMovement, VintageCoverage } from '@/types/stock';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

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
    vintageCoverage: VintageCoverage | null;
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

const current = computed(() => props.analytics.current);

/** The design's tab strip (Figma 449:1577). All seven tabs are built. */
const withCount = (label: string, count: number): string => (count > 0 ? `${label} (${count})` : label);

const DETAIL_TABS = computed<TabItem[]>(() => [
    { label: t('Stock'), value: 'stock' },
    { label: t('Details'), value: 'details' },
    { label: t('Recipe'), value: 'recipe' },
    { label: t('Produce'), value: 'produce' },
    { label: withCount(t('Images'), props.images.length), value: 'images' },
    { label: withCount(t('Docs'), props.techSheets.length + props.documents.length), value: 'docs' },
    { label: t('Pricing'), value: 'pricing' },
    { label: withCount(t('Analysis'), props.bottleAnalyses.length), value: 'analysis' },
]);

/** The category as the design names it ("Finished Product"), not the enum. */
const CATEGORY_NAMES: Record<string, string> = {
    FINISHED: 'Finished Product',
    SEMI_FINISHED: 'Semi-Finished Product',
    RAW_MATERIAL: 'Raw Material',
};
const categoryName = computed(() => t(CATEGORY_NAMES[props.item.category] ?? props.item.category));

/** "LOTR32026 · 12 bottles per case · minimum stock 1.200 bottles". */
const subline = computed(() => {
    const min = props.analytics.current.min_stock_bottles ?? 0;

    return [
        props.item.sku,
        props.analytics.current.bottles_per_case > 1
            ? t(':count bottles per case', { count: props.analytics.current.bottles_per_case })
            : null,
        min > 0 ? t('minimum stock :count :unit', { count: num(min), unit: props.analytics.current.unit }) : null,
    ]
        .filter(Boolean)
        .join(' · ');
});

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

/** Figures derived from the analytics, shared by the Stock tab's cards so they cannot disagree. */
const economics = computed(() => stockEconomics(props.analytics));

const form = useForm({});

async function duplicate(): Promise<void> {
    const ok = await confirmDialog({
        title: t('Duplicate item'),
        description: t('Create a copy of :name? Its stock is not copied.', { name: props.item.name }),
        confirmLabel: t('Duplicate'),
    });
    if (!ok) return;

    form.post(`/inventory/${props.item.id}/duplicate`);
}

async function destroy(): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete item'),
        description: t('Delete :name? Items referenced by orders are deactivated instead.', { name: props.item.name }),
        confirmLabel: t('Delete'),
        tone: 'danger',
    });
    if (!ok) return;

    form.delete(`/inventory/${props.item.id}`);
}
</script>

<template>
    <Head :title="item.name" />
        <div class="flex flex-col gap-5">
            <!-- Title row -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            :href="listReturnUrl('inventory', '/inventory')"
                            class="p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            :aria-label="t('Back to inventory')"
                        >
                            <ArrowLeft class="size-5" :stroke-width="1.5" />
                        </Link>
                        <h2 class="truncate text-xl font-semibold text-foreground">{{ item.name }}</h2>
                        <Badge variant="outline">{{ categoryName }}</Badge>
                        <Badge v-if="item.vintage" variant="neutral">{{ item.vintage }}</Badge>
                    </div>
                    <p class="mt-1 pl-8 text-xs text-muted-foreground">{{ subline }}</p>
                </div>

                <div v-if="can('inventory.bulk') || can('inventory.delete')" class="flex shrink-0 items-center gap-2">
                    <Button v-if="can('inventory.bulk')" variant="outline" size="sm" @click="duplicate">{{ t('Duplicate') }}</Button>
                    <Button
                        v-if="can('inventory.delete')"
                        variant="outline"
                        size="sm"
                        class="border-destructive/40 text-destructive hover:bg-destructive/10"
                        @click="destroy"
                    >
                        <Trash2 class="size-4" :stroke-width="1.5" />
                        {{ t('Delete') }}
                    </Button>
                </div>
            </div>

            <Tabs :items="DETAIL_TABS" :current="activeDetailTab" variant="segmented" @select="activeDetailTab = $event" />

            <template v-if="activeDetailTab === 'stock'">
                <StockPositionSection :analytics="analytics" :economics="economics" :movements="movements" :period="filters.period" />
                <ExitsSection :item-id="item.id" :analytics="analytics" :period="filters.period" />
                <EconomicsSection
                    :analytics="analytics"
                    :economics="economics"
                    :vintage-coverage="vintageCoverage"
                    :item-name="item.name"
                />
                <MovementsSection :item-id="item.id" :unit="current.unit" :movements="movements" />
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
</template>
