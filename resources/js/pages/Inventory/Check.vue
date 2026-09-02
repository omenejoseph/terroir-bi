<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { History, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { cn } from '@/lib/cn';
import { formatQuantity } from '@/lib/money';
import { categoryLabel } from '@/lib/stock';
import type { InventoryItem } from '@/types/inventory';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * Inventory check (Figma `271:12639`) — the physical count sheet.
 *
 * The design groups it as a card per category, then bands per group and
 * subcategory ("WINE — WHITE", "TASTING BOARDS"), with System beside an
 * editable Physical Count and the Difference derived.
 *
 * Only rows whose count differs from the system are submitted: the action
 * writes one reconciliation movement per adjusted item, so sending unchanged
 * rows would add ledger noise for no change.
 */
const props = defineProps<{
    items: InventoryItem[];
    history: { id: string; created_at: string | null; net_difference?: string }[];
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);

const MODULE_TABS: TabItem[] = [
    { label: 'Inventory', href: '/inventory' },
    { label: 'Analytics', href: '/inventory-analytics' },
    { label: 'Inventory Spend', href: '/inventory-spend' },
    { label: 'Inventory Check', href: '/inventory-check' },
];

const search = ref('');
const saving = ref(false);

/** Physical counts, seeded from the system so an untouched sheet is a no-op. */
const counts = reactive<Record<string, string>>(
    Object.fromEntries(props.items.map((item) => [item.id, trim(item.current_stock)])),
);

function trim(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) return props.items;

    return props.items.filter(
        (i) => i.name.toLowerCase().includes(term) || i.sku.toLowerCase().includes(term),
    );
});

/** Category card → band (group — subcategory) → rows, mirroring the design. */
const sections = computed(() => {
    const byCategory = new Map<string, Map<string, InventoryItem[]>>();

    for (const item of visible.value) {
        const band = [item.group, item.subcategory].filter(Boolean).join(' — ') || 'Ungrouped';
        const bands = byCategory.get(item.category) ?? new Map<string, InventoryItem[]>();
        bands.set(band, [...(bands.get(band) ?? []), item]);
        byCategory.set(item.category, bands);
    }

    return [...byCategory.entries()].map(([category, bands]) => ({
        category,
        bands: [...bands.entries()].map(([band, rows]) => ({ band, rows })),
    }));
});

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
    <AppLayout title="Inventory check">
        <div class="flex flex-col gap-5">
            <PageHeader title="Inventory">
                <template #actions>
                    <Button variant="outline" size="sm" :disabled="!history.length">
                        <History class="size-4" :stroke-width="1.5" />
                        History<template v-if="history.length"> ({{ history.length }})</template>
                    </Button>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" current="Inventory Check" />

            <div class="flex flex-wrap items-center gap-3">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search name or SKU…"
                    class="h-9 max-w-xs flex-1 rounded-lg border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
                <div class="ml-auto flex items-center gap-2">
                    <Button variant="outline" size="sm" :disabled="!changed.length" @click="reset">
                        <X class="size-4" :stroke-width="1.5" />
                        Cancel
                    </Button>
                    <Button size="sm" :disabled="!changed.length || saving" @click="save">
                        {{ saving ? 'Saving…' : 'Save Changes' }}<template v-if="changed.length">
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
                        <thead class="border-b border-border text-left text-13 text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-6 py-2.5 font-medium">Product</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Size</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Vintage</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Unit</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">System</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Physical Count</th>
                                <th scope="col" class="px-6 py-2.5 text-right font-medium">Difference</th>
                            </tr>
                        </thead>

                        <tbody v-for="band in section.bands" :key="band.band" class="divide-y divide-border">
                            <tr class="bg-muted/50">
                                <th
                                    colspan="7"
                                    scope="colgroup"
                                    class="px-6 py-2 text-left text-2xs font-medium tracking-[0.08em] text-muted-foreground uppercase"
                                >
                                    {{ band.band }}
                                </th>
                            </tr>
                            <tr
                                v-for="item in band.rows"
                                :key="item.id"
                                :class="
                                    cn(difference(item) !== null && 'bg-[color-mix(in_oklch,var(--color-primary)_5%,transparent)]')
                                "
                            >
                                <td class="px-6 py-3 font-medium">{{ item.name }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ item.unit_size ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ item.vintage ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ item.unit }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                    {{ formatQuantity(item.current_stock, locale) }}
                                </td>
                                <td class="px-4 py-3">
                                    <input
                                        v-model="counts[item.id]"
                                        inputmode="decimal"
                                        :aria-label="`Physical count for ${item.name}`"
                                        class="h-8 w-28 rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                        </tbody>
                    </table>
                </div>

                <p
                    v-if="section.bands.some((b) => b.rows.some((r) => difference(r) !== null))"
                    class="border-t border-border bg-[color-mix(in_oklch,var(--color-primary)_5%,transparent)] px-6 py-2.5 text-13 font-medium"
                >
                    {{ section.bands.flatMap((b) => b.rows).filter((r) => difference(r) !== null).length }} item(s)
                    modified
                </p>
            </Card>

            <p v-if="!sections.length" class="py-10 text-center text-sm text-muted-foreground">
                No active items to count.
            </p>
        </div>
    </AppLayout>
</template>
