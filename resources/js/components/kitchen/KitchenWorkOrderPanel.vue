<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { KitchenItem, KitchenWorkOrder } from '@/types/kitchen';
import type { ComboboxOption } from '@/types/ui';

/**
 * New / edit prep ticket: a title, a day, who it is for, and its lines — each a kitchen item or
 * free text ("staff meal"). Saving replaces the lines as a whole. Prep tickets never move stock.
 */
const props = defineProps<{ open: boolean; order: KitchenWorkOrder | null; items: KitchenItem[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const isEdit = computed(() => props.order !== null);

interface Line {
    item_id: string | null;
    custom_name: string;
    quantity: string;
    unit: string;
    notes: string;
}

const blankLine = (): Line => ({ item_id: null, custom_name: '', quantity: '', unit: '', notes: '' });

const form = useForm({
    title: '',
    date: new Date().toISOString().slice(0, 10),
    assigned_to: '',
    notes: '',
    items: [blankLine()] as Line[],
});

watch(
    () => [props.open, props.order?.id],
    () => {
        if (!props.open) return;

        const o = props.order;
        form.defaults({
            title: o?.title ?? '',
            date: o?.date ?? new Date().toISOString().slice(0, 10),
            assigned_to: o?.assigned_to ?? '',
            notes: o?.notes ?? '',
            items: o
                ? o.items.map((l) => ({ item_id: l.item_id, custom_name: l.custom_name ?? '', quantity: String(Number(l.quantity)), unit: l.unit ?? '', notes: l.notes ?? '' }))
                : [blankLine()],
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const itemOptions = computed<ComboboxOption[]>(() =>
    props.items.filter((i) => i.is_active).map((i) => ({ value: i.id, label: i.name, description: i.unit })),
);

function unitOf(line: Line): string {
    return line.unit || props.items.find((i) => i.id === line.item_id)?.unit || '';
}

const canSubmit = computed(
    () =>
        !form.processing &&
        form.title.trim() !== '' &&
        form.date !== '' &&
        form.items.every((l) => (l.item_id !== null || l.custom_name.trim() !== '') && Number.parseFloat(l.quantity) > 0),
);

function submit(): void {
    form
        .transform((d) => ({
            title: d.title.trim(),
            date: d.date,
            assigned_to: d.assigned_to.trim() === '' ? null : d.assigned_to.trim(),
            notes: d.notes.trim() === '' ? null : d.notes.trim(),
            items: d.items.map((l) => ({
                item_id: l.item_id,
                custom_name: l.item_id === null ? l.custom_name.trim() : null,
                quantity: Number.parseFloat(l.quantity.replace(',', '.')),
                unit: l.unit.trim() === '' ? null : l.unit.trim(),
                notes: l.notes.trim() === '' ? null : l.notes.trim(),
            })),
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/kitchen/work-orders/${props.order!.id}` : '/kitchen/work-orders', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit prep ticket') : t('New prep ticket')" @close="emit('close')">
        <form id="kitchen-wo-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Title')" required :error="form.errors.title">
                <Input :id="id" v-model="form.title" :invalid="invalid" maxlength="255" :placeholder="t('e.g. Saturday tasting lunch')" />
            </FormField>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('Date')" required :error="form.errors.date">
                    <Input :id="id" v-model="form.date" type="date" :invalid="invalid" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Assigned to')" :error="form.errors.assigned_to">
                    <Input :id="id" v-model="form.assigned_to" :invalid="invalid" maxlength="255" :placeholder="t('Optional')" />
                </FormField>
            </div>

            <section class="flex flex-col gap-3">
                <h3 class="text-sm font-medium">{{ t('Lines') }}</h3>
                <div v-for="(line, i) in form.items" :key="i" class="flex flex-col gap-2 border border-border p-3">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <label :for="`wo-item-${i}`" class="sr-only">{{ t('Item') }}</label>
                            <Combobox
                                :id="`wo-item-${i}`"
                                v-model="line.item_id"
                                :options="itemOptions"
                                clearable
                                :placeholder="t('Kitchen item, or leave empty for free text')"
                            />
                        </div>
                        <button
                            type="button"
                            class="p-2 text-muted-foreground hover:text-destructive disabled:opacity-40"
                            :aria-label="t('Remove line')"
                            :disabled="form.items.length === 1"
                            @click="form.items.splice(i, 1)"
                        >
                            <Trash2 class="size-3.5" :stroke-width="1.5" />
                        </button>
                    </div>
                    <div v-if="line.item_id === null">
                        <label :for="`wo-custom-${i}`" class="sr-only">{{ t('Description') }}</label>
                        <Input :id="`wo-custom-${i}`" v-model="line.custom_name" maxlength="255" :placeholder="t('What to prepare')" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label :for="`wo-qty-${i}`" class="sr-only">{{ t('Quantity') }}</label>
                            <Input :id="`wo-qty-${i}`" v-model="line.quantity" type="number" min="0" step="0.001" inputmode="decimal" :placeholder="t('Quantity')" />
                        </div>
                        <div>
                            <label :for="`wo-unit-${i}`" class="sr-only">{{ t('Unit') }}</label>
                            <Input :id="`wo-unit-${i}`" v-model="line.unit" maxlength="10" :placeholder="unitOf(line) || t('Unit')" />
                        </div>
                    </div>
                    <div>
                        <label :for="`wo-notes-${i}`" class="sr-only">{{ t('Notes') }}</label>
                        <Input :id="`wo-notes-${i}`" v-model="line.notes" maxlength="1000" :placeholder="t('Notes (optional)')" />
                    </div>
                </div>
                <p v-if="form.errors.items" class="text-xs text-destructive">{{ form.errors.items }}</p>
                <Button variant="outline" size="sm" class="self-start" @click="form.items.push(blankLine())">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('Add line') }}
                </Button>
            </section>

            <FormField v-slot="{ id }" :label="t('Notes')" :error="form.errors.notes">
                <Textarea :id="id" v-model="form.notes" :rows="3" :placeholder="t('Optional')" />
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="kitchen-wo-form" :disabled="!canSubmit">{{ isEdit ? t('Save changes') : t('Create prep ticket') }}</Button>
        </template>
    </SidePanel>
</template>
