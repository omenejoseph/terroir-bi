<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { History, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CheckHistoryPanel from '@/components/inventory/CheckHistoryPanel.vue';
import type { CheckHistoryEntry } from '@/components/inventory/CheckHistoryPanel.vue';
import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';
import { formatQuantity } from '@/lib/money';
import { categoryLabel, isWholeUnit } from '@/lib/stock';
import type { InventoryItem, ProductCategory } from '@/types/inventory';

type SheetItem = InventoryItem & { is_older_vintage?: boolean };
import type { SharedProps } from '@/types';
import type { ComboboxOption, TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Inventory check (Figma `271:12639`) — the physical count sheet.
 *
 * A card per type (finished, semi-finished, raw materials), then a band per category and subcategory
 * in the order the Inventory list arranges them, an older vintage sitting right under its product.
 * System sits beside an editable Physical Count and the Difference is derived. Each row carries the
 * product's photo, which makes a miscount easier to spot.
 *
 * Only rows whose count differs from the system are submitted: the action
 * writes one reconciliation movement per adjusted item, so sending unchanged
 * rows would add ledger noise for no change.
 */
const props = defineProps<{
    items: SheetItem[];
    history: CheckHistoryEntry[];
    productCategories: ProductCategory[];
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

const MODULE_TABS: TabItem[] = [
    { label: t('Inventory'), href: '/inventory' },
    { label: t('Analytics'), href: '/inventory-analytics' },
    { label: t('Inventory Spend'), href: '/inventory-spend' },
    { label: t('Inventory Check'), href: '/inventory-check' },
    { label: t('Categories'), href: '/inventory/categories' },
];

const search = ref('');
/**
 * '' = every type. Raw materials are counted here too, so the sheet can be narrowed to one type.
 * Opened from the setup checklist's "Opening stock" step, it starts on finished goods — the
 * wines orders will draw from.
 */
const typeFilter = ref(new URLSearchParams(page.url.split('?', 2)[1] ?? '').get('setup') === 'opening_stock' ? 'FINISHED' : '');
/** A category id from the tree; a top-level one covers its subcategories. '' = every category. */
const categoryFilter = ref('');

const categoryOptions = computed<ComboboxOption[]>(() => [
    { value: '', label: t('All categories') },
    ...props.productCategories
        .filter((c) => typeFilter.value === '' || c.type === typeFilter.value)
        .flatMap((c) => [
            { value: c.id, label: c.name, description: String(c.items_count) },
            ...(c.children ?? []).map((s) => ({ value: s.id, label: `${c.name} · ${s.name}`, description: String(s.items_count) })),
        ]),
]);

/** The picked category plus its subcategories. */
const inCategory = computed<Set<string> | null>(() => {
    if (categoryFilter.value === '') return null;

    const top = props.productCategories.find((c) => c.id === categoryFilter.value);

    return new Set([categoryFilter.value, ...(top?.children ?? []).map((s) => s.id)]);
});

function selectType(value: string): void {
    typeFilter.value = value;

    // A category belongs to one type; drop a pick that no longer fits.
    if (!categoryOptions.value.some((o) => o.value === categoryFilter.value)) categoryFilter.value = '';
}
const saving = ref(false);
const historyOpen = ref(false);

/** Physical counts, seeded from the system so an untouched sheet is a no-op. */
const counts = reactive<Record<string, string>>(
    Object.fromEntries(props.items.map((item) => [item.id, trim(item.current_stock)])),
);

function trim(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}

const TYPES = ['FINISHED', 'SEMI_FINISHED', 'RAW_MATERIAL'];

const typeTabs = computed<TabItem[]>(() => [
    { value: '', label: `${t('All')} (${props.items.length})` },
    ...TYPES.map((type) => ({
        value: type,
        label: `${categoryLabel(type)} (${props.items.filter((i) => i.category === type).length})`,
    })),
]);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    const categoryIds = categoryFilter.value === '' ? null : (inCategory.value as Set<string>);

    return props.items.filter(
        (i) =>
            (typeFilter.value === '' || i.category === typeFilter.value) &&
            (categoryIds === null || (i.product_category_id !== null && categoryIds.has(i.product_category_id))) &&
            (!term ||
                i.name.toLowerCase().includes(term) ||
                i.sku.toLowerCase().includes(term) ||
                String(i.vintage ?? '').includes(term)),
    );
});

/** Type card → category band → subcategory band → rows, in the order the server arranged them. */
const sections = computed(() => {
    type Sub = { sub: string; rows: SheetItem[] };
    type Band = { group: string; subs: Sub[] };
    const byType = new Map<string, Map<string, Map<string, SheetItem[]>>>();

    for (const item of visible.value) {
        const groups = byType.get(item.category) ?? new Map<string, Map<string, SheetItem[]>>();
        const subs = groups.get(item.group ?? '') ?? new Map<string, SheetItem[]>();
        subs.set(item.subcategory ?? '', [...(subs.get(item.subcategory ?? '') ?? []), item]);
        groups.set(item.group ?? '', subs);
        byType.set(item.category, groups);
    }

    return [...byType.entries()].map(([category, groups]) => ({
        category,
        bands: [...groups.entries()].map(
            ([group, subs]): Band => ({
                group: group || t('Ungrouped'),
                subs: [...subs.entries()].map(([sub, rows]): Sub => ({ sub, rows })),
            }),
        ),
    }));
});

/** Enter moves to the next count box, so a whole shelf can be counted without the mouse. */
async function nextCount(event: KeyboardEvent): Promise<void> {
    const inputs = [...document.querySelectorAll<HTMLInputElement>('input[data-count]')];
    const at = inputs.indexOf(event.target as HTMLInputElement);

    await nextTick();
    inputs[at + 1]?.focus();
    inputs[at + 1]?.select();
}

function difference(item: InventoryItem): number | null {
    const counted = Number.parseFloat(counts[item.id] ?? '');
    const system = Number.parseFloat(item.current_stock);

    if (!Number.isFinite(counted) || !Number.isFinite(system)) return null;

    const diff = counted - system;

    return diff === 0 ? null : diff;
}

const changed = computed(() => props.items.filter((item) => difference(item) !== null));

function reset(): void {
    for (const item of props.items) counts[item.id] = trim(item.current_stock);
}

function save(): void {
    if (changed.value.length === 0) return;

    saving.value = true;
    router.post(
        '/inventory-check',
        { items: changed.value.map((item) => ({ item_id: item.id, physical_count: counts[item.id] ?? '0' })) },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}
</script>

<template>
    <Head :title="t('Inventory check')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Inventory')">
                <template #actions>
                    <Button variant="outline" size="sm" @click="historyOpen = true">
                        <History class="size-4" :stroke-width="1.5" />
                        {{ t('History') }}<template v-if="history.length"> ({{ history.length }})</template>
                    </Button>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" :current="t('Inventory Check')" />

            <div class="flex flex-wrap items-center gap-3">
                <Tabs :items="typeTabs" :current="typeFilter" variant="filter" @select="selectType" />
                <div v-if="productCategories.length" class="w-56">
                    <Combobox v-model="categoryFilter" :options="categoryOptions" :placeholder="t('All categories')" />
                </div>
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('Search name, SKU or vintage…')"
                    class="h-9 max-w-xs flex-1 rounded-lg border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
                <div class="ml-auto flex items-center gap-2">
                    <Button variant="outline" size="sm" :disabled="!changed.length" @click="reset">
                        <X class="size-4" :stroke-width="1.5" />
                        {{ t('Cancel') }}
                    </Button>
                    <Button size="sm" :disabled="!changed.length || saving" @click="save">
                        {{ saving ? t('Saving…') : t('Save Changes') }}<template v-if="changed.length">
                            ({{ changed.length }})</template
                        >
                    </Button>
                </div>
            </div>

            <Card v-for="section in sections" :key="section.category" class="overflow-hidden">
                <div class="border-b border-border px-6 py-4">
                    <h3 class="text-base font-semibold">{{ categoryLabel(section.category) }}</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-sm">
                        <thead class="border-b border-border text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Product') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Size') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Vintage') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Unit') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('System') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Physical Count') }}</th>
                                <th scope="col" class="px-6 py-2.5 text-right font-medium">{{ t('Difference') }}</th>
                            </tr>
                        </thead>

                        <tbody v-for="band in section.bands" :key="band.group" class="divide-y divide-border">
                            <tr class="bg-muted/50">
                                <th
                                    colspan="7"
                                    scope="colgroup"
                                    class="px-6 py-2 text-left text-3xs font-medium tracking-[0.08em] text-muted-foreground uppercase"
                                >
                                    {{ band.group }}
                                </th>
                            </tr>
                            <template v-for="sub in band.subs" :key="sub.sub">
                                <tr v-if="sub.sub">
                                    <th colspan="7" scope="colgroup" class="bg-muted/20 px-6 py-1.5 text-left text-3xs font-medium text-muted-foreground">
                                        {{ sub.sub }}
                                    </th>
                                </tr>
                                <tr
                                    v-for="item in sub.rows"
                                    :key="item.id"
                                    :class="
                                        cn(
                                            item.is_older_vintage && 'bg-muted/20',
                                            difference(item) !== null && 'bg-[color-mix(in_oklch,var(--color-primary)_5%,transparent)]',
                                        )
                                    "
                                >
                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3" :class="item.is_older_vintage && 'pl-6'">
                                            <img
                                                v-if="item.image_url"
                                                :src="item.image_url"
                                                :alt="item.name"
                                                loading="lazy"
                                                class="size-10 shrink-0 border border-border object-cover"
                                            />
                                            <span v-else class="size-10 shrink-0 border border-border bg-muted" aria-hidden="true" />
                                            <span :class="item.is_older_vintage ? 'text-muted-foreground' : 'font-medium'">{{ item.name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ item.unit_size ?? '—' }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ item.vintage ?? '—' }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ item.unit }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                        {{ formatQuantity(item.current_stock, locale) }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <input
                                            v-model="counts[item.id]"
                                            data-count
                                            :inputmode="isWholeUnit(item.unit) ? 'numeric' : 'decimal'"
                                            :aria-label="t('Physical count for :name', { name: item.name })"
                                            class="ml-auto block h-8 w-28 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            @input="isWholeUnit(item.unit) && (counts[item.id] = (counts[item.id] ?? '').replace(/[^0-9]/g, ''))"
                                            @keydown.enter.prevent="nextCount"
                                        />
                                    </td>
                                    <td
                                        class="px-6 py-3 text-right tabular-nums"
                                        :class="
                                            difference(item) === null
                                                ? 'text-muted-foreground'
                                                : difference(item)! < 0
                                                  ? 'text-destructive'
                                                  : 'font-medium text-foreground'
                                        "
                                    >
                                        <template v-if="difference(item) === null">—</template>
                                        <template v-else>
                                            {{ difference(item)! > 0 ? '+' : '' }}{{ formatQuantity(String(difference(item)), locale) }}
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p
                    v-if="section.bands.some((b) => b.subs.some((x) => x.rows.some((r) => difference(r) !== null)))"
                    class="border-t border-border bg-[color-mix(in_oklch,var(--color-primary)_5%,transparent)] px-6 py-2.5 text-xs font-medium"
                >
                    {{ t(':count item(s) modified', { count: section.bands.flatMap((b) => b.subs.flatMap((x) => x.rows)).filter((r) => difference(r) !== null).length }) }}
                </p>
            </Card>

            <p v-if="!sections.length" class="py-10 text-center text-sm text-muted-foreground">
                {{ t('No active items to count.') }}
            </p>
        </div>

        <CheckHistoryPanel :open="historyOpen" :history="history" @close="historyOpen = false" />
</template>
