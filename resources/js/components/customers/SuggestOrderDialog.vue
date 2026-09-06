<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import Dialog from '@/components/ui/Dialog.vue';
import Button from '@/components/ui/Button.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatNumber } from '@/lib/money';
import type { CustomerProductRow, CustomerRhythm } from '@/types/customers';
import type { SharedProps } from '@/types';

/**
 * Customer — Show · "Suggest order" (Figma 231:9336). No suggestion engine
 * exists for this, and none is invented here either — every row is a real
 * fact already on this page: a product with a repeat-purchase signal
 * (App\Queries\CustomerProductsQuery::signal()), how many bottles they
 * typically take per order that contains it, and when they last ordered it.
 * The only judgment call is which facts to surface, not any prediction.
 *
 * Deliberately just a suggestion, not a shortcut into Create Order: doing
 * that would mean threading a prefilled customer + lines into
 * CreateOrderPanel/Orders — a change to Orders, not Customers, which this
 * item's own scope was about (the "Change range"/"Suggest upsell" siblings
 * on this page stayed there too).
 */
const props = defineProps<{ open: boolean; products: CustomerProductRow[]; rhythm: CustomerRhythm }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

interface Suggestion {
    inventory_item_id: string;
    name: string;
    vintage: number | null;
    signal: string;
    avgQuantity: number;
    lastOrdered: string | null;
}

/** Repeat-purchase products only, biggest volume first — the same "steady repeat" facts the Products bought table already shows, just filtered to the ones worth reordering. */
const suggestions = computed<Suggestion[]>(() =>
    props.products
        .filter((row): row is CustomerProductRow & { signal: string } => row.signal !== null)
        .sort((a, b) => b.units - a.units)
        .slice(0, 5)
        .map((row) => ({
            inventory_item_id: row.inventory_item_id,
            name: row.name,
            vintage: row.vintage,
            signal: row.signal,
            avgQuantity: Math.max(1, Math.round(row.units / Math.max(1, row.orders_with))),
            lastOrdered: row.last_ordered,
        })),
);

function shortDate(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });
}

function num(n: number): string {
    return formatNumber(n, locale.value);
}
</script>

<template>
    <Dialog :open="open" :title="t('Suggest order')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <p v-if="rhythm.overdue" class="text-sm text-destructive">
                {{
                    t('Overdue by their own rhythm — :days days since their last order, typically :gap.', {
                        days: rhythm.days_since_last ?? 0,
                        gap: rhythm.median_gap_days ? t(':count days', { count: rhythm.median_gap_days }) : t('no established gap'),
                    })
                }}
            </p>

            <ul v-if="suggestions.length" class="flex flex-col divide-y divide-border">
                <li v-for="row in suggestions" :key="row.inventory_item_id" class="flex items-baseline justify-between gap-3 py-2.5 text-sm">
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{ [row.name, row.vintage].filter(Boolean).join(' ') }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ t(':signal · last ordered :date', { signal: row.signal, date: shortDate(row.lastOrdered) }) }}
                        </span>
                    </span>
                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                        {{ t('~:count btl / order', { count: num(row.avgQuantity) }) }}
                    </span>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Not enough order history yet to suggest a repeat purchase.') }}
            </p>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Close') }}</Button>
            <Button href="/orders">{{ t('Go to Orders') }}</Button>
        </template>
    </Dialog>
</template>
