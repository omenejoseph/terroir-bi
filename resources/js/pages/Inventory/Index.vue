<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import NewItemPanel from '@/components/inventory/NewItemPanel.vue';
import AttentionBand from '@/components/ui/AttentionBand.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import LevelBar from '@/components/ui/LevelBar.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { cn } from '@/lib/cn';
import { formatMoney, formatQuantity } from '@/lib/money';
import type { InventoryFilters, InventoryItem } from '@/types/inventory';
import type { Paginated, SharedProps } from '@/types';
import type { AttentionItem, TabItem } from '@/types/ui';

/**
 * Inventory list, following Figma 389:1592: page header with the two primary
 * actions, module tabs, the "Needs attention" band, a search field, category
 * tabs, then the table grouped by product group.
 *
 * The design's "Free to sell" and "Cover" columns are omitted: neither has
 * backing data (no order-line reservations, no exit-rate model). Showing an
 * empty column would read as a bug, and inventing the figures would be worse.
 * Both are listed in docs/design/README.md.
 */
const props = defineProps<{
    items: Paginated<InventoryItem>;
    filters: InventoryFilters;
    attention: AttentionItem[];
}>();

const page = usePage<SharedProps>();
const { can } = useAuth();

const search = ref(props.filters.search ?? '');

/* The design opens New Item as a drawer over the list, not as its own page. */
const newItemOpen = ref(false);

/* Debounced server-side search; `preserveState` keeps focus and the typed value
   through the partial reload, `replace` stops one history entry per keystroke. */
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/inventory',
        { search: props.filters.search ?? undefined, category: props.filters.category ?? undefined, ...overrides },
        { preserveState: true, replace: true, only: ['items', 'filters'] },
    );
}

const MODULE_TABS: TabItem[] = [
    { label: 'Inventory', href: '/inventory' },
    { label: 'Analytics', href: null },
    { label: 'Inventory Spend', href: null },
    { label: 'Inventory Check', href: null },
];

/** Mirrors the InventoryCategory enum; the design shows exactly these three. */
const CATEGORY_TABS: TabItem[] = [
    { value: '', label: 'All' },
    { value: 'FINISHED', label: 'Finished' },
    { value: 'SEMI_FINISHED', label: 'Semi-Finished' },
    { value: 'RAW_MATERIAL', label: 'Raw Materials' },
];

/**
 * The design groups rows by product group with a count and a subtotal. Grouping
 * is applied to the current page rather than the whole result set, so the
 * subtotals describe what is on screen.
 */
const groups = computed(() => {
    const byGroup = new Map<string, InventoryItem[]>();

    for (const item of props.items.data) {
        const key = item.group ?? 'Ungrouped';
        const bucket = byGroup.get(key);
        bucket ? bucket.push(item) : byGroup.set(key, [item]);
    }

    return [...byGroup.entries()].map(([label, rows]) => ({
        label,
        rows,
        onHand: rows.reduce((sum, r) => sum + (Number.parseFloat(r.current_stock) || 0), 0),
        value: rows.reduce(
            (sum, r) => sum + (r.default_price ? r.default_price.minor * (Number.parseFloat(r.current_stock) || 0) : 0),
            0,
        ),
        currency: rows.find((r) => r.default_price)?.default_price?.currency ?? null,
    }));
});

const qty = (v: string | null) => formatQuantity(v, page.props.locale);

/** Derived row badges, matching the design's "Flags" column. */
function flags(item: InventoryItem): string[] {
    const out: string[] = [];

    if (item.min_stock === null) out.push('No min stock');
    if (item.cost_per_unit === null) out.push('No cost');
    if ((Number.parseFloat(item.current_stock) || 0) <= 0) out.push('Zero stock');

    return out;
}
</script>

<template>
    <AppLayout title="Inventory">
        <div class="space-y-5">
            <PageHeader title="Inventory">
                <template #actions>
                    <Button v-if="can('inventory.manage')" variant="outline" size="sm">Bulk Import</Button>
                    <Button v-if="can('inventory.manage')" size="sm" @click="newItemOpen = true">New Item</Button>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" current="Inventory" />

            <AttentionBand :items="attention" />

            <!-- Search + category tabs -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative max-w-xs flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        :stroke-width="1.5"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search name, SKU or vintage…"
                        class="h-9 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                <Tabs
                    :items="CATEGORY_TABS"
                    :current="filters.category ?? ''"
                    variant="segmented"
                    @select="reload({ category: $event || undefined })"
                />

                <span class="ml-auto text-13 text-muted-foreground">{{ items.meta.total }} products</span>
            </div>

            <!-- The table scrolls inside its own container so the page never does. -->
            <div class="overflow-x-auto rounded-lg border border-border bg-card">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="border-b border-border text-left text-13 text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-medium">Name</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Size</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">SKU</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Vintage</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">On hand</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Level</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Flags</th>
                        </tr>
                    </thead>

                    <tbody v-for="group in groups" :key="group.label" class="divide-y divide-border">
                        <!-- Group band: name, count and the page subtotal -->
                        <tr class="bg-muted/50">
                            <th colspan="7" scope="colgroup" class="px-4 py-2 text-left">
                                <span class="text-13 font-semibold text-foreground">{{ group.label }}</span>
                                <span class="text-13 text-muted-foreground"> ({{ group.rows.length }})</span>
                                <span class="text-13 text-muted-foreground">
                                    · {{ qty(String(group.onHand)) }} on hand<template v-if="group.currency">
                                        · {{ formatMoney(group.value, group.currency, page.props.locale) }}</template
                                    >
                                </span>
                            </th>
                        </tr>

                        <tr
                            v-for="item in group.rows"
                            :key="item.id"
                            class="cursor-pointer hover:bg-accent/50"
                            @click="router.visit(`/inventory/${item.id}`)"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="item.image_url"
                                        :src="item.image_url"
                                        alt=""
                                        class="size-8 shrink-0 rounded-md border border-border object-cover"
                                    />
                                    <span
                                        v-else
                                        class="size-8 shrink-0 rounded-md border border-border bg-muted"
                                        aria-hidden="true"
                                    />
                                    <span class="font-medium">{{ item.name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ item.unit_size ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ item.sku }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ item.vintage ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ qty(item.current_stock) }}</td>
                            <td class="px-4 py-3">
                                <LevelBar :value="item.current_stock" :min="item.min_stock">
                                    <template v-if="item.min_stock">
                                        {{ qty(item.current_stock) }} of {{ qty(item.min_stock) }} min
                                    </template>
                                    <template v-else>No min stock set</template>
                                </LevelBar>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="flag in flags(item)" :key="flag" variant="outline">
                                        {{ flag }}
                                    </Badge>
                                    <span v-if="!flags(item).length" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <tbody v-if="items.data.length === 0">
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">
                                No items match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="items.meta.last_page > 1" class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">
                    Page {{ items.meta.current_page }} of {{ items.meta.last_page }}
                </span>
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="items.meta.current_page <= 1"
                        @click="reload({ page: items.meta.current_page - 1 })"
                    >
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="items.meta.current_page >= items.meta.last_page"
                        @click="reload({ page: items.meta.current_page + 1 })"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </div>

        <NewItemPanel :open="newItemOpen" @close="newItemOpen = false" />
    </AppLayout>
</template>
