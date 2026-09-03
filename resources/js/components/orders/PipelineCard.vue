<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type { OrderPipeline } from '@/types/orders';
import type { SharedProps } from '@/types';

/**
 * "Order-to-cash pipeline" (Figma 455:1577): one column per stage carrying the
 * open count, its value, and a bar showing its share of the largest stage.
 * Columns are separated by hairline rules, not cards.
 *
 * Clicking a fulfilment stage filters the table below, which is what the
 * design's subtitle promises. Invoiced and Paid are money stages that cut
 * across the fulfilment ones (an order can be Shipped *and* Paid), so they
 * are not filters — see App\Queries\OrderPipelineQuery for why the stage
 * vocabulary is this system's rather than the design's.
 */
const props = defineProps<{
    pipeline: OrderPipeline;
    /** Currently selected status, so the matching stage reads as active. */
    current: string | null;
    /** Stage keys that filter the table; the rest render as figures only. */
    filterable: string[];
    /**
     * Orders/Index's own card (Figma 455:1577) — "Order-to-cash pipeline" — is
     * the default. The Customer — Show Order History tab (361:2157) reuses
     * this same card under a different name and a dynamic count/value
     * subtitle, so both are overridable rather than forked into two components.
     */
    title?: string;
    description?: string;
}>();

const emit = defineEmits<{ select: [key: string | null] }>();

const { t } = useTranslations();
const locale = computed(() => usePage<SharedProps>().props.locale);

const isFilter = (key: string): boolean => props.filterable.includes(key);
</script>

<template>
    <Card>
        <CardContent class="flex flex-col gap-4 p-6">
            <SectionHeader
                :title="title ?? t('Order-to-cash pipeline')"
                :description="description ?? t('Open orders by stage · click a stage to filter the table below')"
            >
                <template #actions>
                    <!-- @todo Stage period picker. The card follows the page's
                         period tabs today; the design offers a second, card-local
                         range ("This month ▾") that needs its own control. -->
                    <button type="button" class="text-xs text-muted-foreground hover:text-foreground">
                        {{ t('This period') }}
                    </button>
                </template>
            </SectionHeader>

            <div class="grid grid-cols-2 gap-y-6 sm:grid-cols-3 xl:grid-cols-6">
                <component
                    :is="isFilter(stage.key) ? 'button' : 'div'"
                    v-for="(stage, i) in pipeline.stages"
                    :key="stage.key"
                    :type="isFilter(stage.key) ? 'button' : undefined"
                    :aria-pressed="isFilter(stage.key) ? current === stage.key : undefined"
                    class="flex flex-col items-start gap-1 px-4 text-left first:pl-0"
                    :class="[
                        i > 0 && 'xl:border-l xl:border-border',
                        isFilter(stage.key) && 'transition-opacity hover:opacity-70',
                        current === stage.key && 'opacity-100',
                    ]"
                    @click="isFilter(stage.key) ? emit('select', current === stage.key ? null : stage.key) : undefined"
                >
                    <span
                        class="text-xs"
                        :class="current === stage.key ? 'font-semibold text-foreground' : 'text-muted-foreground'"
                    >
                        {{ stage.label }}
                    </span>
                    <span class="text-3xl font-semibold tabular-nums">
                        {{ formatNumber(stage.count, locale) }}
                    </span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ formatMoney(stage.value.minor, stage.value.currency) }}
                    </span>
                    <span class="mt-1 h-1 w-full bg-muted" aria-hidden="true">
                        <span class="block h-full bg-foreground" :style="{ width: `${stage.share * 100}%` }" />
                    </span>
                </component>
            </div>
        </CardContent>
    </Card>
</template>
