<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, ShoppingCart } from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import QuantityStepper from '@/components/orders/QuantityStepper.vue';
import Label from '@/components/ui/Label.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { PublicCatalog, PublicCatalogProduct } from '@/types/publicOrder';

const { t } = useTranslations();

/**
 * The self-service order page a customer reaches via their order token
 * (Customers · "Generate Order Link", Figma 231:9336 — the design specifies
 * only the button that generates this link, not this page). Unauthenticated
 * on purpose: the token is the only credential, so this page never touches
 * the tenant session — it talks directly to the public JSON API
 * (Api\PublicOrderController) that already resolves the token, prices every
 * line server-side, and rate-limits submissions, the same as the outgoing
 * app's own self-service flow did.
 *
 * State is a plain status flag rather than separate booleans: 'loading',
 * 'invalid' (bad or revoked token — the catalog fetch itself is the only
 * validation; there's no second check to drift from it), 'ready', 'placed'.
 */
const props = defineProps<{ token: string }>();

const status = ref<'loading' | 'invalid' | 'ready' | 'placed'>('loading');
const catalog = ref<PublicCatalog | null>(null);
const quantities = ref<Record<string, string>>({});
const notes = ref('');
const submitting = ref(false);
const submitError = ref<string | null>(null);
const placedOrderNumber = ref<string | null>(null);

onMounted(async () => {
    try {
        const response = await fetch(`/api/v1/public/${props.token}/catalog`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            status.value = 'invalid';

            return;
        }

        const body = (await response.json()) as { data: PublicCatalog };
        catalog.value = body.data;
        status.value = 'ready';
    } catch {
        status.value = 'invalid';
    }
});

const showPrices = computed(() => catalog.value !== null && !catalog.value.customer.hide_prices);

/**
 * What a product must be ordered in is a property of that product
 * (`sales_unit`, strict — App\Enums\SalesUnit), never a customer-wide
 * choice: a customer's `allow_single_bottle` only decides which products
 * PublicCatalogQuery even offers them (bottles-only items are excluded
 * entirely for a cases-only customer), not what unit an offered item uses.
 * The catalog's price is always per bottle; case pricing multiplies it by
 * that product's own case size, matching exactly what
 * Api\PublicOrderController::resolvedUnitPrice does when it prices the order
 * for real, so the total shown here never surprises the customer at submit.
 */
function unitPriceMinor(product: PublicCatalogProduct): number {
    if (!product.price) return 0;

    return product.sales_unit === 'cases'
        ? product.price.minor * Math.max(1, product.bottles_per_case ?? 1)
        : product.price.minor;
}

function unitLabel(product: PublicCatalogProduct): string {
    if (product.sales_unit !== 'cases') return t('bottle');

    return product.bottles_per_case && product.bottles_per_case > 1
        ? t('case of :count', { count: product.bottles_per_case })
        : t('case');
}

/** The product's title as the winery prints it: "R3 2025 — 750 ml". */
function productTitle(product: PublicCatalogProduct): string {
    const base = [product.name, product.vintage].filter(Boolean).join(' ');

    return product.unit_size ? `${base} — ${product.unit_size}` : base;
}

function packaging(product: PublicCatalogProduct): string {
    return product.sales_unit === 'cases' && product.bottles_per_case && product.bottles_per_case > 1
        ? t(':count bottles/case', { count: product.bottles_per_case })
        : '';
}

function quantityOf(id: string): number {
    return Number.parseInt(quantities.value[id] ?? '', 10) || 0;
}

/** Products under their group heading, then subcategory heading — the order the catalog arrives in. */
const search = ref('');

/** Matches name, vintage, SKU, size, group and subcategory, so "red 2021" or "grappa" both find things. */
const visibleProducts = computed(() => {
    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    const all = catalog.value?.products ?? [];

    if (words.length === 0) return all;

    return all.filter((p) => {
        const haystack = [p.name, p.vintage, p.sku, p.unit_size, p.group, p.subcategory].filter(Boolean).join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
});

const sections = computed(() => {
    const groups: { group: string; subs: { sub: string; products: PublicCatalogProduct[] }[] }[] = [];

    for (const product of visibleProducts.value) {
        const groupName = product.group ?? t('Other');
        const subName = product.subcategory ?? '';
        let group = groups.find((g) => g.group === groupName);

        if (!group) groups.push((group = { group: groupName, subs: [] }));

        let sub = group.subs.find((s) => s.sub === subName);

        if (!sub) group.subs.push((sub = { sub: subName, products: [] }));

        sub.products.push(product);
    }

    return groups;
});

const lines = computed(() => {
    if (catalog.value === null) return [];

    return catalog.value.products
        .map((product) => ({ product, quantity: Number.parseInt(quantities.value[product.id] ?? '', 10) || 0 }))
        .filter((line) => line.quantity > 0);
});

/** "3 cases" / "1 bottle" — the unit the line is ordered in, pluralised. */
function lineUnit(product: PublicCatalogProduct, quantity: number): string {
    if (product.sales_unit === 'cases') return quantity === 1 ? t('case') : t('cases');

    return quantity === 1 ? t('bottle') : t('bottles');
}

/** For case lines, the bottle count the cases add up to — "(36 bottles)". */
function lineBottles(product: PublicCatalogProduct, quantity: number): string {
    if (product.sales_unit !== 'cases') return '';

    const bottles = quantity * Math.max(1, product.bottles_per_case ?? 1);

    return `(${bottles} ${bottles === 1 ? t('bottle') : t('bottles')})`;
}

const currency = computed(() => catalog.value?.products.find((p) => p.price)?.price?.currency ?? 'EUR');

const totalMinor = computed(() =>
    lines.value.reduce((sum, line) => sum + unitPriceMinor(line.product) * line.quantity, 0),
);

function goToSummary(): void {
    document.getElementById('po-summary')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function submit(): Promise<void> {
    submitError.value = null;

    if (lines.value.length === 0) {
        submitError.value = t('Add a quantity to at least one product before submitting.');

        return;
    }

    submitting.value = true;

    try {
        const response = await fetch(`/api/v1/public/${props.token}/orders`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                // Explicit, from the item's own sales_unit — never guessed
                // from the customer alone, which is what let a bottles-only
                // item be submitted as cases (or vice versa) and rejected.
                items: lines.value.map((line) => ({
                    inventory_item_id: line.product.id,
                    quantity: line.quantity,
                    unit_type: line.product.sales_unit,
                })),
                notes: notes.value.trim() || null,
            }),
        });

        if (response.status === 429) {
            submitError.value = t('Too many orders from this link recently. Please try again later.');

            return;
        }

        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as { message?: string } | null;
            submitError.value = body?.message ?? t('Something went wrong placing this order. Please try again.');

            return;
        }

        const body = (await response.json()) as { data: { order_number: string } };
        placedOrderNumber.value = body.data.order_number;
        status.value = 'placed';
    } catch {
        submitError.value = t('Something went wrong placing this order. Please try again.');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head :title="t('Place an order')" />

    <div class="min-h-screen bg-background px-4 pb-8">
        <div class="mx-auto w-full max-w-2xl space-y-6">
            <div class="sticky top-0 z-10 -mx-4 border-b border-border bg-background px-4">
                <header class="flex items-center gap-2.5 py-3">
                    <AppLogo :size="28" />
                    <span class="text-sm font-semibold">{{ catalog?.business_name ?? 'Terroir' }}</span>
                    <LanguageSwitcher class="ml-auto" />
                </header>

                <input
                    v-if="status === 'ready' && catalog && catalog.products.length > 0"
                    v-model="search"
                    type="search"
                    :placeholder="t('Search products…')"
                    :aria-label="t('Search products')"
                    class="mb-3 h-9 w-full border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div v-if="status === 'loading'" class="flex justify-center py-20">
                <div class="size-6 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-foreground" />
            </div>

            <Card v-else-if="status === 'invalid'">
                <CardContent class="py-16 text-center text-sm text-muted-foreground">
                    {{ t("This link isn't valid. It may have been regenerated or revoked — ask for a new one.") }}
                </CardContent>
            </Card>

            <Card v-else-if="status === 'placed'">
                <CardContent class="flex flex-col items-center gap-3 py-16 text-center">
                    <CheckCircle2 class="size-10 text-emerald-600" :stroke-width="1.5" />
                    <h1 class="text-lg font-semibold">{{ t('Thanks for your order') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ t('Order #:number has been placed.', { number: placedOrderNumber ?? '' }) }}</p>
                </CardContent>
            </Card>

            <template v-else-if="catalog">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ t('Place an Order') }}</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('Welcome, :name', { name: catalog.customer.company_name }) }}
                    </p>
                </div>


                <p v-if="catalog.products.length > 0 && visibleProducts.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    {{ t('No product matches.') }}
                </p>

                <p v-if="catalog.products.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    {{ t('Nothing is available to order right now.') }}
                </p>

                <section v-for="section in sections" :key="section.group" class="space-y-2">
                    <h2 class="border-b border-border pb-1.5 text-base font-semibold">{{ section.group }}</h2>

                    <div v-for="sub in section.subs" :key="sub.sub" class="space-y-1">
                        <h3
                            v-if="sub.sub"
                            class="pt-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ sub.sub }}
                        </h3>

                        <div
                            v-for="product in sub.products"
                            :key="product.id"
                            class="flex items-center gap-3 border-b border-border py-3 last:border-b-0"
                        >
                            <img
                                v-if="product.image_url"
                                :src="product.image_url"
                                :alt="product.name"
                                loading="lazy"
                                class="size-14 shrink-0 border border-border object-cover"
                            />
                            <span v-else class="size-14 shrink-0 border border-border bg-muted" aria-hidden="true" />

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ productTitle(product) }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="showPrices && product.price">
                                        {{ formatMoney(unitPriceMinor(product), product.price.currency) }} /
                                    </template>
                                    {{ product.sales_unit === 'cases' ? t('case') : product.sales_unit === 'bottles' ? t('bottle') : t('unit') }}
                                    <template v-if="packaging(product)"> · {{ packaging(product) }}</template>
                                </p>
                            </div>

                            <QuantityStepper
                                :model-value="quantityOf(product.id)"
                                :min="0"
                                :label="product.name"
                                @update:model-value="quantities[product.id] = String($event)"
                            />
                        </div>
                    </div>
                </section>

                <p v-if="lines.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    <ShoppingCart class="mx-auto mb-2 size-8 opacity-40" :stroke-width="1.5" />
                    {{ t('Select products above to start your order.') }}
                </p>

                <Card v-else id="po-summary" class="scroll-mt-20">
                    <CardHeader class="p-4 pb-2">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <ShoppingCart class="size-4" />
                            {{ t('Order summary') }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 p-4 pt-0">
                        <div class="space-y-2">
                            <div v-for="line in lines" :key="line.product.id" class="flex items-center justify-between gap-3 text-sm">
                                <span>
                                    {{ line.quantity }} {{ lineUnit(line.product, line.quantity) }} × {{ [line.product.name, line.product.vintage].filter(Boolean).join(' ') }}
                                    <span v-if="lineBottles(line.product, line.quantity)" class="ml-1 text-[10px] text-muted-foreground">
                                        {{ lineBottles(line.product, line.quantity) }}
                                    </span>
                                </span>
                                <span v-if="showPrices" class="font-medium tabular-nums">
                                    {{ formatMoney(unitPriceMinor(line.product) * line.quantity, currency) }}
                                </span>
                            </div>
                        </div>

                        <div v-if="showPrices" class="flex items-center justify-between border-t border-border pt-2">
                            <span class="font-medium">
                                {{ t('Total') }} <span class="text-[10px] font-normal text-muted-foreground">{{ t('excl. VAT') }}</span>
                            </span>
                            <span class="text-lg font-bold tabular-nums">{{ formatMoney(totalMinor, currency) }}</span>
                        </div>

                        <div class="space-y-2">
                            <Label for="po-notes">{{ t('Notes (optional)') }}</Label>
                            <Textarea id="po-notes" v-model="notes" :rows="2" :placeholder="t('Delivery instructions, anything else we should know…')" />
                        </div>

                        <Callout v-if="submitError" :title="t(`Couldn't submit that order`)" tone="warning">
                            {{ submitError }}
                        </Callout>

                        <Button type="button" class="w-full" size="lg" :disabled="submitting" @click="submit">
                            {{ submitting ? t('Placing order…') : t('Place order') }}
                        </Button>
                    </CardContent>
                </Card>
            </template>
        </div>

        <!-- Floating cart: the number of products chosen, tapping it jumps to the order summary. -->
        <button
            v-if="status === 'ready' && lines.length > 0"
            type="button"
            :aria-label="t('Order summary')"
            class="fixed right-4 bottom-4 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @click="goToSummary"
        >
            <ShoppingCart class="size-6" :stroke-width="1.75" />
            <span class="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-foreground px-1 text-[11px] leading-5 font-semibold text-background tabular-nums">
                {{ lines.length }}
            </span>
        </button>
    </div>
</template>
