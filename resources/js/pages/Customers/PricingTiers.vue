<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PricingTierFormPanel from '@/components/customers/PricingTierFormPanel.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { PricingTierRow } from '@/types/customers';
import type { TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Customers · Pricing tiers: the named price books a customer can be put on, each with a default
 * rebate. A customer's own rebate still overrides the tier's. Absolute per-item tier prices are set
 * from the product in Inventory, so this page only shows how many each tier has.
 *
 * Add and edit open in a slide-in panel over the table, like the other list pages. Deleting a tier
 * never deletes customers: they keep existing, without a tier.
 */
defineProps<{
    tiers: PricingTierRow[];
    /**
     * A few priced wines with what each tier pays (App\Services\Pricing\TierPricePreview —
     * resolved by the real pricing engine, tier prices included). Empty without tiers or priced wines.
     */
    preview?: { id: string; name: string; vintage: string | null; list_price: number; currency: string; prices: Record<string, number> }[];
}>();

const { can } = useAuth();
const { t } = useTranslations();

const canManage = computed(() => can('customers.manage'));

const MODULE_TABS = computed<TabItem[]>(() => [
    { label: t('Customers'), href: '/customers' },
    { label: t('Analytics'), href: '/customers-analytics' },
    { label: t('Categories'), href: '/customers/categories' },
    { label: t('Pricing tiers'), href: '/customers/pricing-tiers' },
]);

const panelOpen = ref(false);
const editing = ref<PricingTierRow | null>(null);

function openNew(): void {
    editing.value = null;
    panelOpen.value = true;
}

function openEdit(tier: PricingTierRow): void {
    editing.value = tier;
    panelOpen.value = true;
}

async function remove(tier: PricingTierRow): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete :name', { name: tier.name }),
        description:
            tier.customers_count > 0 || tier.prices_count > 0
                ? t(':customers customer(s) will keep their record but lose this tier, and its :prices item price(s) are removed.', {
                      customers: tier.customers_count,
                      prices: tier.prices_count,
                  })
                : t('No customers or item prices use this tier.'),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/customers/pricing-tiers/${tier.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Pricing tiers')" />
        <div class="space-y-5">
            <PageHeader
                :title="t('Pricing tiers')"
                :description="t('Price books for your customers, each with a default rebate. A customer\'s own rebate overrides it.')"
            >
                <template v-if="canManage" #actions>
                    <Button size="sm" @click="openNew">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New tier') }}
                    </Button>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" :current="t('Pricing tiers')" />

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Name') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Rebate %') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Customers') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Item prices') }}</th>
                                <th v-if="canManage" scope="col" class="w-20 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tier in tiers"
                                :key="tier.id"
                                class="border-b border-border last:border-b-0 hover:bg-muted/40"
                                :class="canManage && 'cursor-pointer'"
                                @click="canManage && openEdit(tier)"
                            >
                                <td class="px-4 py-3">
                                    <span class="block text-sm font-medium text-foreground">{{ tier.name }}</span>
                                    <span v-if="tier.description" class="mt-0.5 block text-muted-foreground">{{ tier.description }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Number(tier.rebate_percent) }}%</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ tier.customers_count }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ tier.prices_count }}</td>
                                <td v-if="canManage" class="px-4 py-3" @click.stop>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :title="t('Edit')" @click="openEdit(tier)">
                                            <Pencil class="size-3.5" :stroke-width="1.5" />
                                        </button>
                                        <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :title="t('Delete')" @click="remove(tier)">
                                            <Trash2 class="size-3.5" :stroke-width="1.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="tiers.length === 0">
                                <td :colspan="canManage ? 5 : 4" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No pricing tiers yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="preview && preview.length > 0 && tiers.length > 0" class="mt-5 space-y-2">
            <div>
                <h3 class="text-sm font-semibold text-foreground">{{ t('Price preview') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{ t('What a customer on each tier pays, before any rebate of their own. Updates as you change a tier.') }}
                </p>
            </div>
            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Product') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('List price') }}</th>
                                <th
                                    v-for="tier in tiers"
                                    :key="tier.id"
                                    scope="col"
                                    class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase"
                                >
                                    {{ tier.name }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in preview" :key="item.id" class="border-b border-border last:border-b-0">
                                <td class="px-4 py-2.5 text-foreground">{{ item.name }}<span v-if="item.vintage" class="text-muted-foreground"> {{ item.vintage }}</span></td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatMoney(item.list_price, item.currency) }}</td>
                                <td v-for="tier in tiers" :key="tier.id" class="px-4 py-2.5 text-right font-medium tabular-nums">
                                    {{ item.prices[tier.id] !== undefined ? formatMoney(item.prices[tier.id]!, item.currency) : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PricingTierFormPanel v-if="canManage" :open="panelOpen" :tier="editing" @close="panelOpen = false" />
</template>
