<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';

import QuickStockEntry from '@/components/inventory/QuickStockEntry.vue';
import SectionLabel from '@/components/inventory/stock/SectionLabel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatQuantity } from '@/lib/money';
import { formatMovementDate, MOVEMENT_GROUPS, movementTypeLabel } from '@/lib/stock';
import type { StockMovement } from '@/types/stock';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * MOVEMENTS: Quick stock entry, then the movement history with its running balance and a footer that
 * sums what the list shows ("353 bottles out · 745 in · net +392 since 2 May").
 */
const props = defineProps<{ itemId: string; unit: string; movements: StockMovement[] }>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const locale = computed(() => page.props.locale);
const qty = (q: string | null) => formatQuantity(q, locale.value);

const movementFilter = ref('All');
const MOVEMENT_TABS: TabItem[] = Object.keys(MOVEMENT_GROUPS).map((label) => ({ label, value: label }));

const visibleMovements = computed(() => {
    const types = MOVEMENT_GROUPS[movementFilter.value];

    return types === null || types === undefined ? props.movements : props.movements.filter((m) => types.includes(m.type));
});

/** What the whole list adds up to — out, in, net — and where it starts. Movements arrive newest first. */
/** A real minus sign (−), as the design draws negative quantities. */
const signed = (q: string): string => {
    const value = Number.parseFloat(q);

    return `${value > 0 ? '+' : value < 0 ? '−' : ''}${formatQuantity(String(Math.abs(value)), locale.value)}`;
};

const summary = computed(() => {
    if (props.movements.length === 0) return null;

    let out = 0;
    let into = 0;

    for (const m of props.movements) {
        const q = Number.parseFloat(m.quantity);
        if (q < 0) out += -q;
        else into += q;
    }

    const oldest = props.movements[props.movements.length - 1]!;
    const since = oldest.created_at
        ? new Date(oldest.created_at).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' })
        : null;
    // "Balance before the bottling run": the stock just before the latest production-in.
    const run = props.movements.find((m) => m.type === 'PRODUCTION_IN');
    const before = run ? Number.parseFloat(run.balance) - Number.parseFloat(run.quantity) : null;

    return { out, into, net: into - out, since, before };
});

const n = (v: number) => v.toLocaleString(locale.value, { maximumFractionDigits: 3 });
</script>

<template>
    <section class="flex flex-col gap-3">
        <SectionLabel>{{ t('Movements') }}</SectionLabel>

        <!-- Quick stock entry -->
        <div v-if="can('inventory.stock')" class="border border-border bg-card">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-base font-semibold">{{ t('Quick stock entry') }}</h3>
            </div>
            <div class="p-6">
                <QuickStockEntry :item-id="itemId" :unit="unit" />
            </div>
        </div>

        <!-- Movement history -->
        <div class="border border-border bg-card">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-6 py-4">
                <div>
                    <h3 class="text-base font-semibold">{{ t('Movement history') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t(':count movements · running balance so you can see stock rebuilt then drain', { count: movements.length }) }}
                    </p>
                </div>
                <Button variant="outline" size="sm" :href="`/inventory/${itemId}/movements/export`" download>
                    <Download class="size-4" :stroke-width="1.5" />
                    {{ t('Export') }}
                </Button>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-3">
                <Tabs :items="MOVEMENT_TABS" :current="movementFilter" variant="solid" class="self-start" @select="movementFilter = $event" />
                <span class="text-xs text-muted-foreground">{{ t('Corrections excluded from velocity') }}</span>
            </div>

            <div v-if="visibleMovements.length" class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="border-y border-border bg-muted/40 text-left text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Date') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Type') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Quantity') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Balance') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Reference') }}</th>
                            <th scope="col" class="py-2.5 pr-4 font-medium">{{ t('Note') }}</th>
                            <th scope="col" class="py-2.5 pr-6 font-medium">{{ t('By') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="movement in visibleMovements" :key="movement.id">
                            <td class="px-6 py-3 whitespace-nowrap">{{ formatMovementDate(movement.created_at, locale) }}</td>
                            <td class="py-3 pr-4">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <Badge :variant="movement.type === 'PRODUCTION_IN' ? 'solid' : 'outline'">{{ movementTypeLabel(movement.type) }}</Badge>
                                    <Badge v-if="movement.is_reconciliation">{{ t('correction') }}</Badge>
                                </div>
                            </td>
                            <td class="py-3 pr-4 tabular-nums">
                                {{ signed(movement.quantity) }}
                            </td>
                            <td class="py-3 pr-4 font-medium tabular-nums">{{ qty(movement.balance) }}</td>
                            <td class="py-3 pr-4 text-muted-foreground">{{ movement.reference ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ movement.note ?? '—' }}</td>
                            <td class="py-3 pr-6">{{ movement.created_by?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-6 py-8 text-sm text-muted-foreground">
                {{ movements.length ? t('No movements of this kind.') : t('No stock movements recorded yet.') }}
            </p>

            <div v-if="summary" class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-6 py-3 text-xs text-muted-foreground">
                <span>
                    {{ t(':out :unit out · :in in · net :net', { out: n(summary.out), unit, in: n(summary.into), net: `${summary.net > 0 ? '+' : ''}${n(summary.net)}` }) }}<template v-if="summary.since"> {{ t('since :date', { date: summary.since }) }}</template>
                </span>
                <span v-if="summary.before !== null">{{ t('Balance before the bottling run: :count', { count: n(summary.before) }) }}</span>
            </div>
        </div>
    </section>
</template>
