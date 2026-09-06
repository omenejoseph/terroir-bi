<script setup lang="ts">
import { computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

import Badge from '@/components/ui/Badge.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatNumber } from '@/lib/money';
import type { OrderStockMismatchRow } from '@/types/stock';
import type { SharedProps } from '@/types';

/**
 * Inventory Spend's "Check order → stock link" (Figma 386:1673) —
 * App\Queries\OrderStockReconciliationQuery's rows, over the page's own
 * window. Lazily fetched: `rows` stays `undefined` until this panel first
 * opens (see InventoryController::spend()'s Inertia::optional).
 */
const props = defineProps<{ open: boolean; rows: OrderStockMismatchRow[] | undefined }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

function close(): void {
    emit('close');
}

/** Fetched once, the first time the panel opens — not re-asked for on every open. */
watch(
    () => props.open,
    (open) => {
        if (open && props.rows === undefined) {
            router.reload({ only: ['reconciliation'] });
        }
    },
);

function num(n: number): string {
    return formatNumber(n, locale.value);
}

function dateTime(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleString(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** "24 recorded, only 10 still on the order" — the plain-language read of a delta. */
function explain(row: OrderStockMismatchRow): string {
    return row.delta < 0
        ? t('Ledger shows more left than the order backs')
        : t('The order backs more than the ledger ever recorded');
}
</script>

<template>
    <SidePanel :open="open" :title="t('Check order → stock link')" @close="close">
        <p class="mb-4 text-sm text-muted-foreground">
            {{
                t(
                    "Every order that should have moved stock, compared against what the ledger actually recorded — over this page's own window. A row here means the two disagree; it does not change any data by itself.",
                )
            }}
        </p>

        <p v-if="rows === undefined" class="py-12 text-center text-sm text-muted-foreground">{{ t('Loading…') }}</p>

        <p v-else-if="rows.length === 0" class="py-12 text-center text-sm text-muted-foreground">
            {{ t('No mismatches in this window.') }}
        </p>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[46rem] text-xs">
                <thead class="border-b border-border bg-muted/40 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">{{ t('Order') }}</th>
                        <th scope="col" class="px-3 py-2 font-medium">{{ t('Item') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('Recorded') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('Current') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('Delta') }}</th>
                        <th scope="col" class="px-3 py-2 font-medium">{{ t('Last movement') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="`${row.order_number}-${row.item_id}`" class="border-b border-border last:border-b-0">
                        <td class="px-3 py-3">
                            <Link
                                v-if="row.order_id"
                                :href="`/orders?order=${row.order_id}`"
                                class="font-medium text-primary underline-offset-2 hover:underline"
                            >
                                {{ row.order_number }}
                            </Link>
                            <span v-else class="font-medium text-muted-foreground">{{ row.order_number }}</span>
                            <span class="mt-0.5 block text-muted-foreground">
                                <template v-if="row.order_id">{{ row.customer_name ?? '—' }}</template>
                                <Badge v-else variant="warning">{{ t('Order deleted') }}</Badge>
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <span class="block font-medium">{{ row.item_name }}</span>
                            <span v-if="row.sku" class="block text-muted-foreground">{{ row.sku }}</span>
                        </td>
                        <td class="px-3 py-3 text-right tabular-nums">{{ num(row.recorded_bottles) }}</td>
                        <td class="px-3 py-3 text-right tabular-nums">{{ num(row.current_bottles) }}</td>
                        <td class="px-3 py-3 text-right font-semibold text-destructive tabular-nums">
                            {{ row.delta > 0 ? '+' : '' }}{{ num(row.delta) }}
                        </td>
                        <td class="px-3 py-3 text-muted-foreground">
                            <span class="block">{{ dateTime(row.last_movement_at) }}</span>
                            <span class="block">{{ explain(row) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SidePanel>
</template>
