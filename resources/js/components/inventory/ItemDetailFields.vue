<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import SectionHeader from '@/components/ui/SectionHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';
import { formatQuantity } from '@/lib/money';
import { categoryLabel } from '@/lib/stock';
import type { InventoryItem } from '@/types/inventory';
import type { SharedProps } from '@/types';

/**
 * An item's field list — the Item — View drawer's own "Item details" section
 * (Figma 378:1592). Lifted into its own component so Product Detail's Details
 * tab (449:1577) renders the exact same rows rather than a second, drifting
 * copy of them — see docs/18-inventory-detail-tabs-plan.md's Tier A "Details"
 * entry. The identity strip (badges/SKU/vintage) stays inline in each
 * consumer, since Product Detail already shows those in its own page header.
 */
const props = defineProps<{ item: InventoryItem }>();
const emit = defineEmits<{ edit: [item: InventoryItem] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const locale = computed(() => page.props.locale);
const qty = (q: string | null) => formatQuantity(q, locale.value);

const details = computed(() => {
    const item = props.item;

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
    <section class="flex flex-col gap-3">
        <SectionHeader :title="t('Item details')">
            <template #actions>
                <!-- @todo Inline details edit; for now it opens the shared item form. -->
                <button type="button" class="text-xs text-muted-foreground hover:text-foreground" @click="emit('edit', item)">
                    {{ t('Edit') }}
                </button>
            </template>
        </SectionHeader>
        <dl class="divide-y divide-border text-sm">
            <div v-for="detail in details" :key="detail.label" class="flex items-baseline justify-between gap-3 py-2.5">
                <dt class="shrink-0 text-muted-foreground">{{ detail.label }}</dt>
                <dd :class="cn('truncate text-right font-medium', detail.warn && 'text-destructive')">
                    {{ detail.value }}
                </dd>
            </div>
        </dl>
    </section>
</template>
