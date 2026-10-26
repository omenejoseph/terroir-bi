<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatQuantity } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { KitchenItem, KitchenMovement } from '@/types/kitchen';

/**
 * Adjust stock: book stock in, take it out, or set it to a counted figure — and the item's history
 * underneath. Out is not stopped at zero; the kitchen uses things before anyone books them.
 */
const props = defineProps<{ open: boolean; item: KitchenItem | null; movements: KitchenMovement[] | undefined; canManage: boolean }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const locale = computed(() => usePage<SharedProps>().props.locale);

const form = useForm({ type: 'IN' as 'IN' | 'OUT' | 'ADJUSTMENT', quantity: '', note: '' });

watch(
    () => [props.open, props.item?.id],
    () => {
        form.reset();
        form.clearErrors();
    },
);

const TYPES = computed(() => [
    { value: 'IN', label: t('Stock in') },
    { value: 'OUT', label: t('Stock out') },
    { value: 'ADJUSTMENT', label: t('Set to count') },
]);

const TYPE_LABEL = computed<Record<string, string>>(() => ({ IN: t('In'), OUT: t('Out'), ADJUSTMENT: t('Adjustment') }));

function submit(): void {
    if (props.item === null) return;

    form
        .transform((d) => ({ type: d.type, quantity: Number.parseFloat(d.quantity.replace(',', '.')), note: d.note.trim() === '' ? null : d.note.trim() }))
        .post(`/kitchen/items/${props.item.id}/stock`, {
            preserveScroll: true,
            only: ['items', 'itemMovements'],
            onSuccess: () => form.reset(),
        });
}

const qty = (v: string): string => formatQuantity(v, locale.value);
</script>

<template>
    <SidePanel
        :open="open"
        :title="item?.name ?? t('Stock')"
        :subtitle="item ? t('In stock: :qty :unit', { qty: qty(item.current_stock), unit: item.unit }) : undefined"
        @close="emit('close')"
    >
        <div class="flex flex-col gap-5">
            <form v-if="canManage && item" id="kitchen-adjust-form" class="flex flex-col gap-4 border border-border p-3" @submit.prevent="submit">
                <Tabs :items="TYPES" :current="form.type" @select="(v) => (form.type = v as 'IN' | 'OUT' | 'ADJUSTMENT')" />
                <FormField
                    v-slot="{ id, invalid }"
                    :label="form.type === 'ADJUSTMENT' ? t('Counted stock (:unit)', { unit: item.unit }) : t('Quantity (:unit)', { unit: item.unit })"
                    required
                    :error="form.errors.quantity"
                >
                    <Input :id="id" v-model="form.quantity" :invalid="invalid" type="number" min="0" step="0.001" inputmode="decimal" />
                </FormField>
                <FormField v-slot="{ id }" :label="t('Note')" :error="form.errors.note">
                    <Input :id="id" v-model="form.note" maxlength="1000" :placeholder="t('Optional')" />
                </FormField>
                <div class="flex justify-end">
                    <Button type="submit" size="sm" :disabled="form.processing || form.quantity.trim() === ''">{{ t('Save') }}</Button>
                </div>
            </form>

            <section>
                <h3 class="mb-2 text-sm font-semibold">{{ t('History') }}</h3>
                <p v-if="movements === undefined" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>
                <p v-else-if="movements.length === 0" class="text-xs text-muted-foreground">{{ t('No movements yet.') }}</p>
                <ul v-else class="divide-y divide-border border border-border text-xs">
                    <li v-for="m in movements" :key="m.id" class="flex items-start justify-between gap-3 px-3 py-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ TYPE_LABEL[m.type] }}</p>
                            <p v-if="m.note" class="text-muted-foreground">{{ m.note }}</p>
                            <p class="text-muted-foreground">
                                {{ m.at ? new Date(m.at).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : '' }}
                                <template v-if="m.by"> · {{ m.by }}</template>
                            </p>
                        </div>
                        <span class="shrink-0 font-semibold tabular-nums" :class="m.quantity.startsWith('-') ? 'text-destructive' : ''">
                            {{ m.quantity.startsWith('-') ? '' : '+' }}{{ qty(m.quantity) }}
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </SidePanel>
</template>
