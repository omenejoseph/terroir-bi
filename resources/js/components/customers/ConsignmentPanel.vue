<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Loader2, PackageCheck, Plus, Undo2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Input from '@/components/ui/Input.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { CustomerConsignment } from '@/types/customers';
import type { ProductOption } from '@/types/orders';
import type { ComboboxOption } from '@/types/ui';
import type { SharedProps } from '@/types';

/**
 * Customer — Show · Consignment tab (Figma 231:9336's "Komisija"). Aggregates
 * every one of the customer's consignment placements into one running ledger
 * — what's still at the customer, what's sold, what's come back — and lets
 * you record a sale/return or place new goods without hunting through
 * individual orders. Mirrors order-mgmt's customer-level consignment tab:
 * `placements` only lists what's still open (see
 * CustomerConsignmentService::placements()) so a fully sold-through, closed
 * placement stops cluttering the ledger once it's settled.
 */
const props = defineProps<{
    customerId: string;
    /** Undefined until this tab is first opened. */
    consignment: CustomerConsignment | undefined;
    /** The full sellable catalogue for "Place goods" — undefined until asked for. */
    catalog: ProductOption[] | undefined;
    canManage: boolean;
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

type Mode = 'view' | 'sale' | 'return' | 'place';

const mode = ref<Mode>('view');
const qty = ref<Record<string, string>>({});
const placeRows = ref<{ inventoryItemId: string | null; quantity: string }[]>([{ inventoryItemId: null, quantity: '' }]);
const err = ref('');
const processing = ref(false);

function reset(): void {
    qty.value = {};
    err.value = '';
    mode.value = 'view';
    placeRows.value = [{ inventoryItemId: null, quantity: '' }];
}

const products = computed(() => props.consignment?.products ?? []);
const placements = computed(() => props.consignment?.placements ?? []);
const hasRemaining = computed(() => products.value.some((p) => p.remaining > 0));
/** Present only once financials.view has actually let the server compute them. */
const showFinancials = computed(() => props.consignment?.total_sold_revenue !== null && props.consignment !== undefined);

function money(m: { minor: number; currency: string } | null): string {
    return m === null ? '—' : formatMoney(m.minor, m.currency);
}

watch(mode, (value) => {
    if (value === 'place' && props.catalog === undefined) {
        router.reload({ only: ['pricingCatalog'] });
    }
});

const CATALOG_OPTIONS = computed<ComboboxOption[]>(() =>
    (props.catalog ?? []).map((product) => ({
        value: product.id,
        label: [product.name, product.vintage].filter(Boolean).join(' '),
        description: product.unit_size ?? undefined,
        keywords: [product.sku, product.vintage === null ? '' : String(product.vintage)].filter(Boolean) as string[],
    })),
);

function submitSaleOrReturn(kind: 'sale' | 'return'): void {
    const items = products.value
        .map((p) => ({
            inventory_item_id: p.inventory_item_id,
            quantity: Number.parseInt(qty.value[p.inventory_item_id] || '0', 10) || 0,
        }))
        .filter((i) => i.quantity > 0);

    if (items.length === 0) return;

    err.value = '';
    processing.value = true;

    router.post(
        `/customers/${props.customerId}/consignment/${kind}`,
        { items },
        {
            preserveScroll: true,
            // A partial reload — see CustomerPriceDialog.vue's submit() for
            // why a bare post without `only` would drop every other Optional
            // prop this page may already have loaded.
            only: ['consignment'],
            onSuccess: () => reset(),
            onError: (errors) => {
                err.value = Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function addPlaceRow(): void {
    placeRows.value.push({ inventoryItemId: null, quantity: '' });
}

function submitPlace(): void {
    const items = placeRows.value
        .filter((row) => row.inventoryItemId !== null && (Number.parseInt(row.quantity || '0', 10) || 0) > 0)
        .map((row) => ({ inventory_item_id: row.inventoryItemId as string, quantity: Number.parseInt(row.quantity, 10) }));

    if (items.length === 0) return;

    err.value = '';
    processing.value = true;

    router.post(
        `/customers/${props.customerId}/consignment/place`,
        { items },
        {
            preserveScroll: true,
            only: ['consignment'],
            onSuccess: () => reset(),
            onError: (errors) => {
                err.value = Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function shortDate(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>

<template>
    <div class="space-y-4">
        <!-- Aggregate rollup -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="border border-border bg-card p-3">
                <p class="text-2xs text-muted-foreground">{{ t('Still at customer') }}</p>
                <p class="mt-1 text-sm font-semibold tabular-nums">{{ consignment?.total_remaining ?? 0 }} {{ t('btl') }}</p>
            </div>
            <template v-if="showFinancials">
                <div class="border border-border bg-card p-3">
                    <p class="text-2xs text-muted-foreground">{{ t('Sold (invoiced)') }}</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums">{{ money(consignment?.total_sold_revenue ?? null) }}</p>
                </div>
                <div class="border border-border bg-card p-3">
                    <p class="text-2xs text-muted-foreground">{{ t('Gross profit') }}</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums">{{ money(consignment?.total_sold_gross_profit ?? null) }}</p>
                </div>
                <div class="border border-border bg-card p-3">
                    <p class="text-2xs text-muted-foreground">{{ t('Margin') }}</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums">
                        {{ consignment?.total_sold_margin_percent === null ? '—' : `${consignment?.total_sold_margin_percent}%` }}
                    </p>
                </div>
            </template>
        </div>

        <div class="border border-border bg-card">
            <div class="border-b border-border px-6 py-4">
                <SectionHeader
                    :title="t('Consignment')"
                    :description="t('Running balance across all placements. Record sales and returns here; they allocate oldest-first.')"
                />
            </div>

            <div class="space-y-4 p-6">
                <p v-if="err" class="text-xs text-destructive">{{ err }}</p>

                <!-- Place goods -->
                <div v-if="mode === 'place'" class="space-y-2">
                    <p class="text-2xs font-medium tracking-wide text-muted-foreground uppercase">{{ t('Place goods (bottles)') }}</p>
                    <div v-for="(row, i) in placeRows" :key="i" class="flex items-center gap-2">
                        <Combobox
                            class="flex-1"
                            :model-value="row.inventoryItemId"
                            :placeholder="t('Select wine…')"
                            :empty-text="t('No product matches.')"
                            :options="CATALOG_OPTIONS"
                            @update:model-value="row.inventoryItemId = $event"
                        />
                        <Input v-model="row.quantity" type="number" min="0" :placeholder="t('btl')" class="w-24 text-right tabular-nums" />
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="addPlaceRow">
                        <Plus class="size-3" :stroke-width="1.5" />
                        {{ t('Add wine') }}
                    </button>
                </div>

                <!-- Ledger -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b border-border text-2xs text-muted-foreground uppercase">
                            <tr>
                                <th scope="col" class="py-1.5 pr-2 text-left font-medium">{{ t('Wine') }}</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-medium">{{ t('Placed') }}</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-medium">{{ t('Sold') }}</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-medium">{{ t('Returned') }}</th>
                                <th scope="col" class="px-2 py-1.5 text-right font-medium">{{ t('Remaining') }}</th>
                                <th v-if="showFinancials && mode === 'view'" scope="col" class="px-2 py-1.5 text-right font-medium">
                                    {{ t('Margin') }}
                                </th>
                                <th v-if="mode !== 'view'" scope="col" class="w-[96px] py-1.5 pl-2 text-right font-medium">
                                    {{ mode === 'sale' ? t('Sell') : t('Return') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in products" :key="p.inventory_item_id" class="border-b border-border last:border-0">
                                <td class="py-2 pr-2">{{ p.name }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ p.placed }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ p.sold }}</td>
                                <td class="px-2 py-2 text-right text-muted-foreground tabular-nums">{{ p.returned }}</td>
                                <td class="px-2 py-2 text-right font-medium tabular-nums">{{ p.remaining }}</td>
                                <td v-if="showFinancials && mode === 'view'" class="px-2 py-2 text-right text-muted-foreground tabular-nums">
                                    {{ p.margin_percent === null ? '—' : `${p.margin_percent}%` }}
                                </td>
                                <td v-if="mode !== 'view'" class="py-1 pl-2">
                                    <Input
                                        v-model="qty[p.inventory_item_id]"
                                        type="number"
                                        min="0"
                                        :max="p.remaining"
                                        placeholder="0"
                                        :disabled="p.remaining === 0"
                                        class="text-right text-xs tabular-nums"
                                    />
                                </td>
                            </tr>
                            <tr v-if="products.length === 0">
                                <td colspan="6" class="py-6 text-center text-muted-foreground">{{ t('No consignment goods yet.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Actions -->
                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="mode === 'view'">
                        <template v-if="canManage">
                            <Button size="sm" :disabled="!hasRemaining" @click="mode = 'sale'">
                                <PackageCheck class="size-3.5" :stroke-width="1.5" />
                                {{ t('Record sales') }}
                            </Button>
                            <Button variant="outline" size="sm" :disabled="!hasRemaining" @click="mode = 'return'">
                                <Undo2 class="size-3.5" :stroke-width="1.5" />
                                {{ t('Record return') }}
                            </Button>
                            <Button variant="ghost" size="sm" class="ml-auto" @click="mode = 'place'">
                                <Plus class="size-3.5" :stroke-width="1.5" />
                                {{ t('Place goods') }}
                            </Button>
                        </template>
                    </template>
                    <template v-else>
                        <Button
                            size="sm"
                            :disabled="processing"
                            @click="mode === 'place' ? submitPlace() : submitSaleOrReturn(mode === 'sale' ? 'sale' : 'return')"
                        >
                            <Loader2 v-if="processing" class="size-3.5 animate-spin" :stroke-width="1.5" />
                            {{ mode === 'sale' ? t('Save sales') : mode === 'return' ? t('Save return') : t('Place') }}
                        </Button>
                        <Button variant="ghost" size="sm" :disabled="processing" @click="reset">{{ t('Cancel') }}</Button>
                    </template>
                </div>

                <!-- Placements -->
                <div v-if="placements.length > 0" class="border-t border-border pt-3">
                    <p class="mb-2 text-2xs font-semibold tracking-wide text-muted-foreground uppercase">{{ t('Placements') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="pl in placements"
                            :key="pl.order_id"
                            type="button"
                            class="inline-flex items-center gap-1 border border-border px-2 py-1 text-2xs text-muted-foreground hover:bg-muted/40"
                            @click="router.visit(`/orders?order=${pl.order_id}`)"
                        >
                            #{{ pl.order_number }} · {{ pl.remaining }} {{ t('btl') }}
                            <span v-if="pl.closed_at !== null">· {{ t('Closed') }}</span>
                            <span class="text-muted-foreground">{{ shortDate(pl.placed_at) }}</span>
                        </button>
                    </div>
                </div>

                <!-- @todo Per-report sale/return history. The sold quantities
                     already surface in the Order History tab, so a separate
                     consignment log here would just repeat it. -->
            </div>
        </div>
    </div>
</template>
