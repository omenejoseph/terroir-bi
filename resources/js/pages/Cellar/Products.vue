<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { History, Pencil, Plus, Search, Trash2, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import ProductFormPanel from '@/components/cellar/ProductFormPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Combobox from '@/components/ui/Combobox.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { csrfHeader } from '@/lib/csrf';
import { formatMoney, formatUnitPrice } from '@/lib/money';
import type { EnologicalProduct, ProtocolNeed } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { ComboboxOption, MenuItem, TabItem } from '@/types/ui';

/**
 * Cellar › Products — the enological stock: what's on the shelf, what's low,
 * what it's worth, every stock movement, and what the protocols in use will
 * need (with draft purchase orders per supplier for the deficits).
 */
const props = defineProps<{ products: EnologicalProduct[]; needs: ProtocolNeed[]; suppliers: ComboboxOption[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const finance = computed(() => can('finance.view'));
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const locale = computed(() => page.props.locale);
const money = (m: number) => formatMoney(m, currency.value);

const tab = ref<'products' | 'needs'>('products');
const deficits = computed(() => props.needs.filter((n) => n.deficit > 0));
const tabs = computed<TabItem[]>(() => [
    { label: t('Products'), value: 'products' },
    { label: t('Protocol needs · :n', { n: deficits.value.length }), value: 'needs' },
]);

const low = (p: EnologicalProduct) => p.min_stock !== null && Number(p.current_stock) < Number(p.min_stock);
const value = computed(() => props.products.reduce((n, p) => n + (p.cost_per_unit ? p.cost_per_unit.minor * Number(p.current_stock) : 0), 0));

// Filters
const category = ref<string | null>(null);
const search = ref('');
const categories = computed(() => [...new Set(props.products.map((p) => p.category ?? 'OTHER'))].sort());
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.products.filter((p) => (category.value === null || (p.category ?? 'OTHER') === category.value) && (q === '' || [p.name, p.manufacturer ?? '', p.category ?? ''].some((x) => x.toLowerCase().includes(q))));
});
const supplierName = (id: string | null) => props.suppliers.find((s) => s.value === id)?.label ?? '—';

// Selection + bulk edit
const selected = ref<string[]>([]);
const allSelected = computed(() => shown.value.length > 0 && shown.value.every((p) => selected.value.includes(p.id)));
function toggleAll(): void {
    selected.value = allSelected.value ? [] : shown.value.map((p) => p.id);
}

// Panels
const formOpen = ref(false);
const editing = ref<EnologicalProduct | null>(null);
const stockFor = ref<EnologicalProduct | null>(null);
const importOpen = ref(false);
const bulkOpen = ref(false);

const menu = computed<MenuItem[]>(() => [
    { key: 'edit', label: t('Edit'), icon: Pencil },
    { key: 'stock', label: t('Stock & history'), icon: History },
    ...(can('cellar.delete') ? [{ key: 'delete', label: t('Delete'), icon: Trash2, destructive: true }] : []),
]);

async function onMenu(p: EnologicalProduct, key: string): Promise<void> {
    if (key === 'edit') {
        editing.value = p;
        formOpen.value = true;
    }
    if (key === 'stock') stockFor.value = p;
    if (key === 'delete' && (await confirmDialog({ title: t('Delete :name?', { name: p.name }), description: t('A product already used in additions is deactivated instead.'), confirmLabel: t('Delete'), tone: 'danger' }))) {
        router.delete(`/cellar/products/${p.id}`, { preserveScroll: true });
    }
}

// Stock adjustment + ledger
const delta = ref('');
const adjNote = ref('');
const movements = ref<{ id: string; date: string | null; delta: string; balance_after: string; source: string; note: string | null; by: string | null }[] | null>(null);
watch(stockFor, async (p) => {
    delta.value = '';
    adjNote.value = '';
    movements.value = null;
    if (!p) return;
    const res = await fetch(`/cellar/products/${p.id}/movements`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    movements.value = res.ok ? (await res.json()).data : [];
});
const SOURCE_LABELS: Record<string, string> = {
    opening: 'Opening stock',
    addition: 'Addition',
    addition_corrected: 'Addition corrected',
    addition_reversed: 'Addition deleted',
    manual: 'Manual adjustment',
    import: 'Import',
};
function adjust(): void {
    const d = parseDecimal(delta.value);
    if (!stockFor.value || d === null || d === 0) return;
    const id = stockFor.value.id;
    router.post(`/cellar/products/${id}/adjust-stock`, { delta: d, note: adjNote.value || null }, {
        preserveScroll: true,
        onSuccess: () => (stockFor.value = props.products.find((p) => p.id === id) ?? null),
    });
}

// Import
const importText = ref('');
const importPreview = ref<{ rows: Record<string, unknown>[]; errors: string[] } | null>(null);
async function previewImport(): Promise<void> {
    const res = await fetch('/cellar/products/import', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify({ text: importText.value, dry_run: true }),
    });
    const json = await res.json();
    importPreview.value = json.data ?? { rows: [], errors: Object.values((json.errors ?? {}) as Record<string, string[]>).flat() };
}
function commitImport(): void {
    router.post('/cellar/products/import', { text: importText.value }, {
        preserveScroll: true,
        onSuccess: () => {
            importOpen.value = false;
            importText.value = '';
            importPreview.value = null;
        },
    });
}

// Bulk edit: only the fields filled in are sent.
const bulk = ref({ category: '', supplier_id: null as string | null, min_stock: '', manufacturer: '', is_active: '' });
function applyBulk(): void {
    const fields: Record<string, unknown> = {};
    if (bulk.value.category) fields.category = bulk.value.category;
    if (bulk.value.supplier_id) fields.supplier_id = bulk.value.supplier_id;
    if (bulk.value.min_stock !== '') fields.min_stock = parseDecimal(bulk.value.min_stock);
    if (bulk.value.manufacturer) fields.manufacturer = bulk.value.manufacturer;
    if (bulk.value.is_active !== '') fields.is_active = bulk.value.is_active === '1';
    router.patch('/cellar/products/bulk', { ids: selected.value, fields } as never, {
        preserveScroll: true,
        onSuccess: () => {
            bulkOpen.value = false;
            selected.value = [];
        },
    });
}

function draftOrders(): void {
    router.post('/cellar/products/draft-orders', {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t('Enological products')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Enological products')">
                <template v-if="canManage" #actions>
                    <Button size="sm" variant="outline" @click="importOpen = true"><Upload class="size-3.5" /> {{ t('Import') }}</Button>
                    <Button size="sm" @click="((editing = null), (formOpen = true))"><Plus class="size-3.5" /> {{ t('Product') }}</Button>
                </template>
            </PageHeader>
            <CellarTabs current="products" />

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="t('Total products')" :value="String(products.length)" />
                <StatCard :label="t('Low stock')" :value="String(products.filter(low).length)" :alert="products.some(low)" />
                <StatCard v-if="finance" :label="t('Inventory value')" :value="money(Math.round(value))" />
                <StatCard :label="t('Protocol deficits')" :value="String(deficits.length)" :alert="deficits.length > 0" />
            </div>

            <Tabs :items="tabs" :current="tab" @select="tab = $event as 'products' | 'needs'" />

            <template v-if="tab === 'products'">
                <div class="flex flex-wrap items-center gap-2">
                    <Tabs variant="filter" :items="[{ label: t('All'), value: '' }, ...categories.map((c) => ({ label: c, value: c }))]" :current="category ?? ''" @select="category = $event || null" />
                    <div class="relative ml-auto min-w-48">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" class="pl-8" :placeholder="t('Search product…')" />
                    </div>
                    <Button v-if="canManage && selected.length" size="sm" variant="outline" @click="bulkOpen = true">{{ t('Bulk edit (:n)', { n: selected.length }) }}</Button>
                </div>
                <Card class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="w-8 px-3 py-2"><input v-if="canManage" type="checkbox" class="size-4 accent-primary" :checked="allSelected" :aria-label="t('Select all')" @change="toggleAll" /></th>
                                <th class="px-2 py-2 text-left font-medium">{{ t('Product') }}</th>
                                <th class="px-2 py-2 text-left font-medium">{{ t('Category') }}</th>
                                <th class="px-2 py-2 text-right font-medium">{{ t('Stock') }}</th>
                                <th class="px-2 py-2 text-right font-medium">{{ t('Minimum') }}</th>
                                <th v-if="finance" class="px-2 py-2 text-right font-medium">{{ t('Cost') }}</th>
                                <th class="hidden px-2 py-2 text-left font-medium md:table-cell">{{ t('Supplier') }}</th>
                                <th class="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in shown" :key="p.id" :class="cn('border-t border-border', !p.is_active && 'opacity-50')">
                                <td class="px-3 py-1.5"><input v-if="canManage" v-model="selected" type="checkbox" :value="p.id" class="size-4 accent-primary" :aria-label="p.name" /></td>
                                <td class="px-2 py-1.5"><p class="font-medium">{{ p.name }}</p><p v-if="p.manufacturer" class="text-[11px] text-muted-foreground">{{ p.manufacturer }}</p></td>
                                <td class="px-2 py-1.5">{{ p.category }}</td>
                                <td :class="cn('px-2 py-1.5 text-right tabular-nums', low(p) && 'font-semibold text-destructive')">{{ qty(p.current_stock) }} {{ p.unit }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-muted-foreground">{{ p.min_stock ? qty(p.min_stock) : '—' }}</td>
                                <td v-if="finance" class="px-2 py-1.5 text-right tabular-nums">{{ p.cost_per_unit ? `${formatUnitPrice(p.cost_per_unit.minor, currency)}/${p.unit}` : '—' }}</td>
                                <td class="hidden px-2 py-1.5 md:table-cell">{{ supplierName(p.supplier_id) }}</td>
                                <td class="px-2"><DropdownMenu v-if="canManage" :items="menu" @select="onMenu(p, $event)" /></td>
                            </tr>
                            <tr v-if="shown.length === 0"><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ t('No products yet.') }}</td></tr>
                        </tbody>
                    </table>
                </Card>
            </template>

            <template v-else>
                <Card class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">{{ t('Product') }}</th>
                                <th class="px-2 py-2 text-right font-medium">{{ t('Needed') }}</th>
                                <th class="px-2 py-2 text-right font-medium">{{ t('Stock') }}</th>
                                <th class="px-2 py-2 text-right font-medium">{{ t('Deficit') }}</th>
                                <th class="px-2 py-2 text-left font-medium">{{ t('Supplier') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(n, i) in needs" :key="i" class="border-t border-border">
                                <td class="px-3 py-1.5 font-medium">{{ n.product_name }}<span v-if="!n.enological_product_id" class="ml-1 text-[10px] text-muted-foreground">({{ t('not in stock list') }})</span></td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ qty(n.total_needed) }} {{ n.unit }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ qty(n.current_stock) }}</td>
                                <td :class="cn('px-2 py-1.5 text-right tabular-nums', n.deficit > 0 && 'font-semibold text-destructive')">{{ n.deficit > 0 ? qty(n.deficit) : '—' }}</td>
                                <td class="px-2 py-1.5">{{ n.supplier?.name ?? '—' }}</td>
                            </tr>
                            <tr v-if="needs.length === 0"><td colspan="5" class="px-3 py-8 text-center text-muted-foreground">{{ t('No lot in fermentation or ageing uses a protocol with additive doses.') }}</td></tr>
                        </tbody>
                    </table>
                </Card>
                <div v-if="canManage" class="flex justify-end">
                    <Button :disabled="deficits.filter((d) => d.enological_product_id).length === 0" @click="draftOrders">{{ t('Draft purchase orders for the deficits') }}</Button>
                </div>
            </template>
        </div>

        <ProductFormPanel :open="formOpen" :product="editing" :suppliers="suppliers" @close="formOpen = false" />

        <SidePanel :open="stockFor !== null" :title="stockFor?.name ?? ''" :subtitle="stockFor ? `${t('Stock')}: ${qty(stockFor.current_stock)} ${stockFor.unit}` : ''" @close="stockFor = null">
            <div class="flex flex-col gap-4">
                <div v-if="canManage" class="grid grid-cols-[100px_1fr_auto] items-end gap-2">
                    <FormField v-slot="{ id }" :label="t('Change')"><Input :id="id" v-model="delta" inputmode="decimal" placeholder="+10 / -5" /></FormField>
                    <FormField v-slot="{ id }" :label="t('Reason')"><Input :id="id" v-model="adjNote" :placeholder="t('Stock count, delivery…')" /></FormField>
                    <Button size="sm" :disabled="(parseDecimal(delta) ?? 0) === 0" @click="adjust">{{ t('Apply') }}</Button>
                </div>
                <p class="text-sm font-medium">{{ t('Stock history') }}</p>
                <p v-if="movements === null" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>
                <div v-for="m in movements ?? []" :key="m.id" class="flex gap-2 border-b border-border py-1.5 text-xs last:border-0">
                    <span class="w-28 shrink-0 text-muted-foreground">{{ m.date ? new Date(m.date).toLocaleString(locale, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '' }}</span>
                    <span class="min-w-0 flex-1"><span class="font-medium">{{ t(SOURCE_LABELS[m.source] ?? m.source) }}</span><template v-if="m.note"> · {{ m.note }}</template><template v-if="m.by"> · {{ m.by }}</template></span>
                    <span :class="cn('w-16 shrink-0 text-right tabular-nums', Number(m.delta) < 0 ? 'text-destructive' : 'text-success')">{{ Number(m.delta) > 0 ? '+' : '' }}{{ qty(m.delta) }}</span>
                    <span class="w-16 shrink-0 text-right tabular-nums text-muted-foreground">{{ qty(m.balance_after) }}</span>
                </div>
                <p v-if="movements && movements.length === 0" class="text-xs text-muted-foreground">{{ t('No movements yet.') }}</p>
            </div>
        </SidePanel>

        <SidePanel :open="importOpen" :title="t('Import products')" @close="importOpen = false">
            <div class="flex flex-col gap-3">
                <p class="text-xs text-muted-foreground">{{ t('Paste rows from a spreadsheet (TSV or CSV) with a header row: name, category, unit, stock, min stock, cost, manufacturer, packaging, supplier, notes. Existing products are matched by name.') }}</p>
                <Textarea v-model="importText" :rows="8" class="font-mono text-xs" />
                <Button size="sm" variant="outline" class="w-fit" :disabled="importText.trim() === ''" @click="previewImport">{{ t('Preview') }}</Button>
                <template v-if="importPreview">
                    <p v-for="(e, i) in importPreview.errors" :key="i" class="text-xs text-destructive">{{ e }}</p>
                    <div v-if="importPreview.rows.length" class="max-h-64 overflow-auto border border-border text-xs">
                        <div v-for="(r, i) in importPreview.rows" :key="i" class="flex justify-between border-b border-border px-2 py-1 last:border-0">
                            <span class="font-medium">{{ r.name }}</span>
                            <span class="text-muted-foreground">{{ [r.category, r.current_stock !== undefined ? `${r.current_stock} ${r.unit ?? ''}` : null].filter(Boolean).join(' · ') }}</span>
                        </div>
                    </div>
                </template>
            </div>
            <template #footer>
                <Button variant="outline" size="lg" @click="importOpen = false">{{ t('Cancel') }}</Button>
                <Button size="lg" :disabled="!importPreview || importPreview.errors.length > 0 || importPreview.rows.length === 0" @click="commitImport">{{ t('Import :n rows', { n: importPreview?.rows.length ?? 0 }) }}</Button>
            </template>
        </SidePanel>

        <SidePanel :open="bulkOpen" :title="t('Bulk edit :n products', { n: selected.length })" @close="bulkOpen = false">
            <div class="flex flex-col gap-4">
                <p class="text-xs text-muted-foreground">{{ t('Only the fields you fill in are changed. Stock is never bulk-edited.') }}</p>
                <FormField v-slot="{ id }" :label="t('Category')"><Input :id="id" v-model="bulk.category" /></FormField>
                <FormField v-slot="{ id }" :label="t('Supplier')"><Combobox :id="id" v-model="bulk.supplier_id" :options="suppliers" clearable /></FormField>
                <FormField v-slot="{ id }" :label="t('Minimum stock')"><Input :id="id" v-model="bulk.min_stock" inputmode="decimal" /></FormField>
                <FormField v-slot="{ id }" :label="t('Manufacturer')"><Input :id="id" v-model="bulk.manufacturer" /></FormField>
                <FormField v-slot="{ id }" :label="t('Active')"><Select :id="id" v-model="bulk.is_active" :options="[{ value: '', label: t('Leave as is') }, { value: '1', label: t('Active') }, { value: '0', label: t('Inactive') }]" /></FormField>
            </div>
            <template #footer>
                <Button variant="outline" size="lg" @click="bulkOpen = false">{{ t('Cancel') }}</Button>
                <Button size="lg" @click="applyBulk">{{ t('Apply') }}</Button>
            </template>
        </SidePanel>
    </AppLayout>
</template>
