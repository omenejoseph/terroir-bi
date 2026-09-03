<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { customerSubtitle } from '@/lib/customers';
import type { Customer } from '@/types/customers';

/**
 * Merge the customers selected on the list (Figma 230:2395's selection bar).
 *
 * The design shows the Merge affordance but not what it opens — a merge needs
 * one decision the bar cannot express: which record survives. So this drawer
 * asks exactly that and nothing else, then hands the choice to
 * CustomerMergeService, which is what actually moves orders, prices and tokens.
 *
 * A merge deletes records, which is why it sits behind `customers.delete` and
 * why the drawer states plainly what will happen before the button is armed.
 */
const props = defineProps<{ open: boolean; customers: Customer[] }>();
const emit = defineEmits<{ close: []; merged: [] }>();
const { t } = useTranslations();

const winner = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) winner.value = props.customers[0]?.id ?? null;
    },
);

const losers = computed(() => props.customers.filter((c) => c.id !== winner.value));

const form = useForm<{ winner_id: string; loser_ids: string[] }>({ winner_id: '', loser_ids: [] });

function submit(): void {
    if (winner.value === null || losers.value.length === 0) return;

    form.winner_id = winner.value;
    form.loser_ids = losers.value.map((c) => c.id);

    form.post('/customers/merge', { onSuccess: () => emit('merged') });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Merge customers')" @close="emit('close')">
        <div class="flex flex-col gap-5">
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Pick the record to keep. Every order, price and order link belonging to the others moves onto it, and the others are deleted.',
                    )
                }}
            </p>

            <fieldset class="flex flex-col gap-2">
                <legend class="sr-only">{{ t('Customer to keep') }}</legend>
                <label
                    v-for="customer in customers"
                    :key="customer.id"
                    class="flex cursor-pointer items-start gap-3 border p-3 transition-colors"
                    :class="winner === customer.id ? 'border-primary bg-muted/40' : 'border-border hover:border-foreground/40'"
                >
                    <input v-model="winner" type="radio" :value="customer.id" class="mt-1 shrink-0" name="winner" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ customer.company_name }}</span>
                        <span class="block truncate text-xs text-muted-foreground">
                            {{ customerSubtitle(customer.customer_type, customer.city) ?? customer.email }}
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground tabular-nums">
                            {{ t(':count orders', { count: customer.order_count ?? 0 }) }}
                        </span>
                    </span>
                    <span
                        v-if="winner === customer.id"
                        class="shrink-0 bg-primary px-2 py-0.5 text-2xs font-medium text-primary-foreground"
                    >
                        {{ t('Keeps') }}
                    </span>
                </label>
            </fieldset>

            <Callout v-if="losers.length > 0" variant="warning" :title="t('This cannot be undone')">
                {{
                    t(':names will be deleted once their records have moved.', {
                        names: losers.map((c) => c.company_name).join(', '),
                    })
                }}
            </Callout>

            <p v-if="form.errors.winner_id" class="text-xs text-destructive" role="alert">
                {{ form.errors.winner_id }}
            </p>
            <p v-if="form.errors.loser_ids" class="text-xs text-destructive" role="alert">
                {{ form.errors.loser_ids }}
            </p>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button :disabled="form.processing || losers.length === 0" @click="submit">
                {{ t('Merge :count into this one', { count: losers.length }) }}
            </Button>
        </template>
    </SidePanel>
</template>
