<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { AttentionCard } from '@/types/customers';
import type { MoneyValue } from '@/types/inventory';
import type { SharedProps } from '@/types';

/**
 * "Needs attention" on a customer's overview (Figma 231:9336).
 *
 * Each card states a condition, the number behind it and the one thing to do
 * about it. The band is absent when nothing is wrong — CustomerAttentionQuery
 * emits no card rather than a zero, so an empty band means "nothing here",
 * not "we did not check".
 */
const props = defineProps<{ cards: AttentionCard[]; customerId: string; canManage: boolean }>();
const emit = defineEmits<{ act: [action: AttentionCard['action']] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

const text = (value: string | MoneyValue): string =>
    typeof value === 'string' ? value : formatMoney(value.minor, value.currency, locale.value);

const ACTION_LABELS = computed<Record<AttentionCard['action'], string>>(() => ({
    contact: t('Call · repeat order'),
    orders: t('Open in Orders'),
    pricing: t('Configure pricing'),
}));
</script>

<template>
    <section v-if="cards.length > 0" class="flex flex-col gap-3">
        <h2 class="text-sm font-semibold">{{ t('Needs attention') }}</h2>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="card in cards"
                :key="card.key"
                class="flex flex-col gap-1 border p-4"
                :class="card.severity === 'critical' ? 'border-destructive' : 'border-border'"
            >
                <span class="flex items-center gap-2 text-xs">
                    <span
                        class="size-2 shrink-0"
                        :class="card.severity === 'critical' ? 'bg-destructive' : 'bg-muted-foreground/50'"
                        aria-hidden="true"
                    />
                    {{ card.label }}
                </span>

                <span
                    class="text-3xl font-semibold tabular-nums"
                    :class="card.severity === 'critical' && 'text-destructive'"
                >
                    {{ text(card.value) }}
                </span>

                <span class="text-xs text-muted-foreground">{{ card.detail }}</span>
                <span class="text-xs text-muted-foreground">{{ text(card.meta) }}</span>

                <div class="mt-3">
                    <Button
                        v-if="card.action === 'contact'"
                        size="sm"
                        :disabled="!canManage"
                        @click="emit('act', card.action)"
                    >
                        {{ ACTION_LABELS[card.action] }}
                    </Button>
                    <Button
                        v-else-if="card.action === 'orders'"
                        variant="outline"
                        size="sm"
                        :href="`/orders?customer_id=${customerId}`"
                    >
                        {{ ACTION_LABELS[card.action] }}
                    </Button>
                    <Button v-else variant="outline" size="sm" @click="emit('act', card.action)">
                        {{ ACTION_LABELS[card.action] }}
                    </Button>
                </div>
            </div>
        </div>
    </section>
</template>
