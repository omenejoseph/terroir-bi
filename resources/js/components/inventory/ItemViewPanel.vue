<script setup lang="ts">
import { computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import MetaStrip from '@/components/ui/MetaStrip.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';
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
const { t } = useTranslations();
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

/**
 * The design's waterfall deducts allocations, open-order reservations and
 * consignment from physical stock. None of the three exists in the schema, so
 * each row says so rather than showing 0 — a zero would assert there are none,
 * which is a claim the data cannot make.
 */
const deductions = [
    { label: t('Allocated'), note: t('allocations are not tracked') },
    { label: t('Reserved by open orders'), note: t('order lines do not reserve stock') },
    { label: t('On consignment'), note: t('consignment is not attributed per item') },
];

const details = computed(() => {
    const item = props.item;

    if (!item) return [];

    return [
        { label: t('Type'), value: categoryLabel(item.category) },
        { label: t('Category'), value: [item.group, item.subcategory].filter(Boolean).join(' · ') || '—' },
        { label: t('Unit size / unit'), value: [item.unit_size, item.unit].filter(Boolean).join(' · ') },
        { label: t('Sales unit'), value: item.sales_unit ?? '—' },
        { label: t('Vintage'), value: item.vintage ? String(item.vintage) : '—' },
        {
            label: t('Min stock'),
            value: item.min_stock ? qty(item.min_stock) : t('Not set — no low-stock alert'),
            warn: !item.min_stock,
        },
        { label: t('Available for sale'), value: item.is_for_sale ? t('Yes') : t('No') },
    ];
});
</script>

<template>
    <SidePanel :open="item !== null" :title="item?.name ?? ''" @close="emit('close')">
        <div v-if="item" class="flex flex-col gap-5">
            <div class="flex flex-col gap-3">
                <MetaStrip :items="[item.sku, item.vintage ? String(item.vintage) : null, item.unit_size]" />

                <div class="flex flex-wrap items-center gap-1.5">
                    <Badge variant="outline">{{ categoryLabel(item.category) }}</Badge>
                    <Badge v-if="item.group" variant="outline">
                        {{ [item.group, item.subcategory].filter(Boolean).join(' · ') }}
                    </Badge>
                    <Badge v-if="item.is_for_sale" variant="outline">{{ t('Available for sale') }}</Badge>
                    <!-- The design flags a data-quality problem with a red outline. -->
                    <Badge v-if="!item.min_stock" variant="warning">{{ t('No min stock') }}</Badge>
                </div>
            </div>

            <!--
              @todo Provenance line. The design reads "Lowest stock of the six
              wines · Updated 8 Aug 2026 by Iva Šimić" — a ranking within its
              group plus the last editor. Needs an audit trail; the ledger
              records movements but not field edits.
            -->

            <Separator />

            <!-- Stock -->
            <section class="flex flex-col gap-3">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <h3 class="text-base font-semibold text-foreground">{{ t('Stock') }}</h3>
                        <span class="text-xs text-muted-foreground">
                            {{ qty(item.current_stock) }} {{ item.unit
                            }}<template v-if="cases !== null"> · {{ t(':count cases', { count: cases }) }}</template>
                        </span>
                    </div>
                    <Button variant="outline" size="sm" :href="`/inventory/${item.id}`">{{ t('Adjust') }}</Button>
                </div>

                <!--
                  The design leads with free-to-sell. We cannot compute it, so
                  physical stock leads instead and the label says which figure
                  this is — rather than presenting physical as if it were
                  available.
                -->
                <p class="flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tabular-nums">{{ qty(item.current_stock) }}</span>
                    <span class="text-sm text-muted-foreground">{{ t('physical stock') }}</span>
                </p>

                <!--
                  The design's free-to-sell waterfall. Allocations and order
                  reservations do not exist in the schema, so those rows say
                  "not tracked" rather than 0 — a zero would assert there are
                  none, which is a claim the data cannot make. The total is
                  withheld for the same reason.
                -->
                <dl class="divide-y divide-border text-sm">
                    <div class="flex items-start justify-between gap-3 py-3">
                        <dt>{{ t('Physical stock') }}</dt>
                        <dd class="shrink-0 font-medium tabular-nums">{{ qty(item.current_stock) }}</dd>
                    </div>
                    <div v-for="row in deductions" :key="row.label" class="flex items-start justify-between gap-3 py-3">
                        <dt class="min-w-0">
                            <span class="block text-muted-foreground">− {{ row.label }}</span>
                            <span class="block text-xs text-muted-foreground">{{ row.note }}</span>
                        </dt>
                        <dd class="shrink-0 text-xs text-muted-foreground">—</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 py-3">
                        <dt class="font-medium">{{ t('= Free to sell') }}</dt>
                        <dd class="shrink-0 text-xs text-muted-foreground">{{ t('needs order reservations') }}</dd>
                    </div>
                </dl>
            </section>

            <Separator />

            <!-- Pricing -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Pricing & margin')">
                    <template #actions>
                        <!-- @todo Inline pricing edit; for now it opens the item. -->
                        <Link :href="`/inventory/${item.id}`" class="text-xs text-muted-foreground hover:text-foreground">
                            {{ t('Edit') }}
                        </Link>
                    </template>
                </SectionHeader>

                <dl class="divide-y divide-border rounded-lg border border-border bg-muted/40 px-4 text-sm">
                    <div class="flex items-baseline justify-between gap-3 py-3">
                        <dt class="text-muted-foreground">{{ t('Default price') }}</dt>
                        <dd :class="cn('font-medium tabular-nums', !item.default_price && 'text-destructive')">
                            {{ money(item.default_price) ?? t('Not set') }}
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 py-3">
                        <dt class="text-muted-foreground">{{ t('Cost per unit') }}</dt>
                        <dd :class="cn('font-medium tabular-nums', !item.cost_per_unit && 'text-destructive')">
                            {{ money(item.cost_per_unit) ?? t('Not set') }}
                        </dd>
                    </div>
                </dl>

                <Callout v-if="!item.cost_per_unit" :title="t('Margin unavailable')">
                    {{ t('Needs a cost per unit, or a recipe to calculate from.') }}
                    <template #action>
                        <Button variant="outline" size="sm" :href="`/inventory/${item.id}`">{{ t('Add cost') }}</Button>
                    </template>
                </Callout>
            </section>

            <Separator />

            <!-- Item details -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Item details')">
                    <template #actions>
                        <!-- @todo Inline details edit; for now it opens the item. -->
                        <Link :href="`/inventory/${item.id}`" class="text-xs text-muted-foreground hover:text-foreground">
                            {{ t('Edit') }}
                        </Link>
                    </template>
                </SectionHeader>
                <dl class="divide-y divide-border text-sm">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 py-2.5"
                    >
                        <dt class="shrink-0 text-muted-foreground">{{ detail.label }}</dt>
                        <dd :class="cn('truncate text-right font-medium', detail.warn && 'text-destructive')">
                            {{ detail.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <Separator />

            <!-- Who's buying it -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Who\'s buying it')">
                    <template #actions>
                        <!-- @todo Filter Orders by this item once Orders is ported. -->
                        <span class="text-xs text-muted-foreground">{{ t('Open in Orders') }}</span>
                    </template>
                </SectionHeader>
                <!--
                  @todo Customer attribution. The design lists each buyer with
                  their share of this item's volume and their last order date.
                  Needs order lines rolled up by customer for this item — the
                  data exists on OrderItem, but no query aggregates it per item.
                -->
                <p class="text-xs text-muted-foreground">{{ t('Per-customer demand is not attributed yet.') }}</p>
            </section>

            <Separator />

            <!-- Stock movements -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Stock movements')">
                    <template #actions>
                        <Link :href="`/inventory/${item.id}`" class="text-xs text-muted-foreground hover:text-foreground">
                            {{ t('View all') }}
                        </Link>
                    </template>
                </SectionHeader>

                <ul v-if="movements.length" class="flex flex-col divide-y divide-border">
                    <li
                        v-for="movement in movements"
                        :key="movement.id"
                        class="flex items-baseline justify-between gap-3 py-2.5 text-sm"
                    >
                        <span class="min-w-0 truncate">
                            {{ movementTypeLabel(movement.type) }}
                            <span v-if="movement.reference" class="text-muted-foreground">
                                · {{ movement.reference }}
                            </span>
                        </span>
                        <span class="flex shrink-0 items-baseline gap-3">
                            <span
                                class="tabular-nums"
                                :class="Number.parseFloat(movement.quantity) < 0 && 'text-destructive'"
                            >
                                {{ Number.parseFloat(movement.quantity) > 0 ? '+' : '' }}{{ qty(movement.quantity) }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ formatMovementDate(movement.created_at, locale) }}
                            </span>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">{{ t('No movements recorded yet.') }}</p>
            </section>

            <Separator />

            <!-- Timeline -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Timeline')" />
                <!--
                  @todo Audit trail. The design shows "Stock adjusted to 420 by
                  Iva Šimić" and "Item created by Iva Šimić" with timestamps.
                  Movements cover adjustments, but creation and field edits are
                  not recorded anywhere.
                -->
                <p class="text-xs text-muted-foreground">{{ t('Item history is not recorded yet.') }}</p>
            </section>
        </div>

        <template #footer>
            <Button v-if="item" variant="outline" class="mr-auto border-destructive/40 text-destructive" :href="`/inventory/${item.id}`">
                {{ t('Delete') }}
            </Button>
            <Button v-if="item" variant="outline" :href="`/inventory/${item.id}`">{{ t('Adjust stock') }}</Button>
            <Button v-if="item" :href="`/inventory/${item.id}`">{{ t('Edit item') }}</Button>
        </template>
    </SidePanel>
</template>
