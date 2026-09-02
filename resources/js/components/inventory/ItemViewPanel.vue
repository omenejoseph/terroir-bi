<script setup lang="ts">
import { computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import MetaStrip from '@/components/ui/MetaStrip.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { categoryLabel, formatMovementDate, movementTypeLabel } from '@/lib/stock';
import type { InventoryItem, MoneyValue } from '@/types/inventory';
import type { StockMovement } from '@/types/stock';
import type { SharedProps } from '@/types';

/**
 * Item — View (Figma `378:1592`).
 *
 * Renders from the list row's own DTO, so opening it costs nothing; only the
 * recent ledger entries are fetched, via an Inertia partial reload keyed on the
 * item. That is the pattern every later View drawer will reuse.
 *
 * Built from the cached layer tree and copy — this frame was not in the export
 * set, so it wants a visual diff once a render exists.
 */
const props = defineProps<{ item: InventoryItem | null; movements: StockMovement[] }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const money = (m: MoneyValue | null) => (m ? formatMoney(m.minor, m.currency, locale.value) : null);
const qty = (q: string | null) => formatQuantity(q, locale.value);

/** Pull this item's movements only once the drawer is actually open. */
watch(
    () => props.item?.id,
    (id) => {
        if (id) router.reload({ only: ['itemMovements'], data: { item: id } });
    },
);

const cases = computed(() => {
    const per = props.item?.bottles_per_case ?? 0;
    const stock = Number.parseFloat(props.item?.current_stock ?? '');

    return per > 0 && Number.isFinite(stock) ? Math.floor(stock / per) : null;
});

const details = computed(() => {
    const item = props.item;

    if (!item) return [];

    return [
        { label: 'Type', value: categoryLabel(item.category) },
        { label: 'Category', value: [item.group, item.subcategory].filter(Boolean).join(' · ') || '—' },
        { label: 'Unit size / unit', value: [item.unit_size, item.unit].filter(Boolean).join(' · ') },
        { label: 'Sales unit', value: item.sales_unit ?? '—' },
        { label: 'Vintage', value: item.vintage ? String(item.vintage) : '—' },
        { label: 'Min stock', value: item.min_stock ? qty(item.min_stock) : 'Not set — no low-stock alert' },
        { label: 'Available for sale', value: item.is_for_sale ? 'Yes' : 'No' },
    ];
});
</script>

<template>
    <SidePanel :open="item !== null" :title="item?.name ?? ''" @close="emit('close')">
        <div v-if="item" class="flex flex-col gap-5">
            <div class="flex flex-col gap-3">
                <MetaStrip :items="[item.sku, item.vintage ? String(item.vintage) : null, item.unit_size]" />

                <div class="flex flex-wrap gap-1.5">
                    <Badge variant="outline">{{ categoryLabel(item.category) }}</Badge>
                    <Badge v-if="item.group" variant="outline">
                        {{ [item.group, item.subcategory].filter(Boolean).join(' · ') }}
                    </Badge>
                    <Badge v-if="item.is_for_sale" variant="success">Available for sale</Badge>
                    <Badge v-if="!item.min_stock" variant="outline">No min stock</Badge>
                </div>
            </div>

            <Separator />

            <!-- Stock -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Stock">
                    <template #actions>
                        <Button variant="outline" size="sm" :href="`/inventory/${item.id}`">Adjust</Button>
                    </template>
                </SectionHeader>

                <p class="text-2xl font-semibold tabular-nums">
                    {{ qty(item.current_stock) }}
                    <span class="text-sm font-normal text-muted-foreground">
                        {{ item.unit }}<template v-if="cases !== null"> · {{ cases }} cases</template>
                    </span>
                </p>

                <!--
                  The design's free-to-sell waterfall. Allocations and order
                  reservations do not exist in the schema, so those rows say
                  "not tracked" rather than 0 — a zero would assert there are
                  none, which is a claim the data cannot make. The total is
                  withheld for the same reason.
                -->
                <dl class="flex flex-col gap-2 rounded-lg border border-border p-4 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt>Physical stock</dt>
                        <dd class="font-medium tabular-nums">{{ qty(item.current_stock) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 text-muted-foreground">
                        <dt>− Reserved by open orders</dt>
                        <dd class="text-13">not tracked</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 text-muted-foreground">
                        <dt>− On consignment</dt>
                        <dd class="text-13">not tracked</dd>
                    </div>
                    <Separator />
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="font-medium">= Free to sell</dt>
                        <dd class="text-13 text-muted-foreground">needs order reservations</dd>
                    </div>
                </dl>
            </section>

            <Separator />

            <!-- Pricing -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Pricing &amp; margin" />

                <dl class="flex flex-col gap-2 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted-foreground">Default price</dt>
                        <dd class="font-medium tabular-nums">{{ money(item.default_price) ?? 'Not set' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted-foreground">Cost per unit</dt>
                        <dd class="font-medium tabular-nums">{{ money(item.cost_per_unit) ?? 'Not set' }}</dd>
                    </div>
                </dl>

                <div v-if="!item.cost_per_unit" class="rounded-lg border border-dashed border-border p-4">
                    <p class="text-sm font-medium">Margin unavailable</p>
                    <p class="mt-1 text-13 text-muted-foreground">
                        Needs a cost per unit, or a recipe to calculate from.
                    </p>
                </div>
            </section>

            <Separator />

            <!-- Item details -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Item details" />
                <dl class="flex flex-col gap-2 text-sm">
                    <div v-for="detail in details" :key="detail.label" class="flex items-baseline justify-between gap-3">
                        <dt class="shrink-0 text-muted-foreground">{{ detail.label }}</dt>
                        <dd class="truncate text-right font-medium">{{ detail.value }}</dd>
                    </div>
                </dl>
            </section>

            <Separator />

            <!-- Stock movements -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Stock movements">
                    <template #actions>
                        <Link :href="`/inventory/${item.id}`" class="text-13 text-muted-foreground hover:text-foreground">
                            View all
                        </Link>
                    </template>
                </SectionHeader>

                <ul v-if="movements.length" class="flex flex-col divide-y divide-border">
                    <li v-for="movement in movements" :key="movement.id" class="flex items-baseline justify-between gap-3 py-2">
                        <span class="min-w-0">
                            <span class="block truncate text-sm">
                                {{ movementTypeLabel(movement.type) }}
                                <span v-if="movement.reference" class="text-muted-foreground">
                                    · {{ movement.reference }}
                                </span>
                            </span>
                            <span class="text-2xs text-muted-foreground">
                                {{ formatMovementDate(movement.created_at, locale) }}
                            </span>
                        </span>
                        <span
                            class="shrink-0 text-sm tabular-nums"
                            :class="Number.parseFloat(movement.quantity) < 0 && 'text-destructive'"
                        >
                            {{ Number.parseFloat(movement.quantity) > 0 ? '+' : '' }}{{ qty(movement.quantity) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-13 text-muted-foreground">No movements recorded yet.</p>
            </section>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Close</Button>
            <Button v-if="item" :href="`/inventory/${item.id}`">Open item</Button>
        </template>
    </SidePanel>
</template>
