<script setup lang="ts">
import { computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';

import Dialog from '@/components/ui/Dialog.vue';
import Button from '@/components/ui/Button.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { CustomerUpsell } from '@/types/customers';

/**
 * Customer — Show · "Suggest upsell" (Figma 231:9336, next to Price ladder).
 * No suggestion engine exists for this either, and none is invented here:
 * the candidate is a real, sellable catalog item in the customer's own
 * cheapest-per-bottle bucket (App\Queries\CustomerUpsellQuery — the same
 * subcategory/group bucketing the Price ladder itself uses) that costs more
 * per bottle and that this customer has never bought. "They might like it"
 * is not claimed — only "it's a real step up from what they already buy".
 *
 * `upsell` is `undefined` until this dialog opens and asks for it (an
 * Inertia::optional prop), the same lazy-load shape as OrderLinkDialog's
 * `token`.
 */
const props = defineProps<{ open: boolean; upsell: CustomerUpsell | undefined }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

watch(
    () => props.open,
    (open) => {
        if (open && props.upsell === undefined) {
            router.reload({ only: ['upsell'] });
        }
    },
    { immediate: true },
);

const bucketLabel = computed(() => {
    const bucket = props.upsell?.bucket;

    if (bucket === null || bucket === undefined) return null;

    return bucket
        .toLowerCase()
        .split(/[\s_-]+/)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
});
</script>

<template>
    <Dialog :open="open" :title="t('Suggest upsell')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <p v-if="upsell === undefined" class="text-sm text-muted-foreground">{{ t('Loading…') }}</p>

            <template v-else-if="upsell.candidates.length > 0">
                <p class="text-sm text-muted-foreground">
                    {{
                        t('Their cheapest bucket by revenue per bottle is :bucket, at :price/btl — a real, pricier :bucket they haven\'t bought yet:', {
                            bucket: bucketLabel ?? '',
                            price: upsell.current_price_per_bottle ? formatMoney(upsell.current_price_per_bottle.minor, upsell.current_price_per_bottle.currency) : '—',
                        })
                    }}
                </p>

                <ul class="flex flex-col divide-y divide-border">
                    <li v-for="row in upsell.candidates" :key="row.inventory_item_id" class="flex items-baseline justify-between gap-3 py-2.5 text-sm">
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ [row.name, row.vintage].filter(Boolean).join(' ') }}</span>
                            <span v-if="row.sku" class="block text-xs text-muted-foreground">{{ row.sku }}</span>
                        </span>
                        <span class="shrink-0 text-xs font-medium tabular-nums">
                            {{ formatMoney(row.default_price.minor, row.default_price.currency) }}/btl
                        </span>
                    </li>
                </ul>
            </template>

            <p v-else class="text-sm text-muted-foreground">
                {{ t('No pricier catalog item is available yet to suggest as a step up from what they already buy.') }}
            </p>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Close') }}</Button>
        </template>
    </Dialog>
</template>
