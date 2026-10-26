<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { ProgramRow } from '@/types/hospitality';

/**
 * Add / edit a program (the old app's program manager). Changing the base price carries every
 * agency price that was set as a rebate along with it; flat agency prices and reservations already
 * taken keep their figures — the hint says so, because that is the part people ask about.
 */
const props = defineProps<{ open: boolean; program: ProgramRow | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const isEdit = computed(() => props.program !== null);

const form = useForm({ name: '', base_price: '', duration_mins: '', max_pax: '', description: '', is_active: true });

watch(
    () => [props.open, props.program?.id],
    () => {
        if (!props.open) return;

        const p = props.program;
        form.defaults({
            name: p?.name ?? '',
            base_price: p ? String(p.base_price.minor / 100) : '',
            duration_mins: p?.duration_mins != null ? String(p.duration_mins) : '',
            max_pax: p?.max_pax != null ? String(p.max_pax) : '',
            description: p?.description ?? '',
            is_active: p?.is_active ?? true,
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function toMinor(value: string): number | null {
    if (value.trim() === '') return null;

    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

function toInt(value: string): number | null {
    const n = Number(value);

    return value.trim() !== '' && Number.isInteger(n) ? n : null;
}

const canSubmit = computed(() => !form.processing && form.name.trim() !== '' && toMinor(form.base_price) !== null);

function submit(): void {
    form
        .transform((data) => ({
            name: data.name.trim(),
            base_price: toMinor(data.base_price),
            duration_mins: toInt(data.duration_mins),
            max_pax: toInt(data.max_pax),
            description: data.description.trim() === '' ? null : data.description.trim(),
            is_active: data.is_active,
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/hospitality/programs/${props.program!.id}` : '/hospitality/programs', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit program') : t('New program')" @close="emit('close')">
        <form id="program-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                <Input :id="id" v-model="form.name" :invalid="invalid" maxlength="255" :placeholder="t('e.g. Classic tasting')" />
            </FormField>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Base price per person')"
                required
                :hint="isEdit ? t('Agency prices set as a rebate follow a new base price. Flat agency prices and existing reservations do not change.') : undefined"
                :error="form.errors.base_price"
            >
                <Input :id="id" v-model="form.base_price" :invalid="invalid" type="number" min="0" step="0.01" inputmode="decimal" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('Duration (minutes)')" :error="form.errors.duration_mins">
                    <Input :id="id" v-model="form.duration_mins" :invalid="invalid" type="number" min="1" step="1" inputmode="numeric" />
                </FormField>
                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Maximum guests')"
                    :hint="t('Shown on the reservation form; not enforced.')"
                    :error="form.errors.max_pax"
                >
                    <Input :id="id" v-model="form.max_pax" :invalid="invalid" type="number" min="1" step="1" inputmode="numeric" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" :label="t('Description')" :error="form.errors.description">
                <Textarea :id="id" v-model="form.description" :rows="3" :placeholder="t('Optional')" />
            </FormField>

            <SwitchRow
                v-if="isEdit"
                v-model="form.is_active"
                :label="t('Active')"
                :hint="t('Inactive programs keep their reservations but cannot be booked.')"
            />
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="program-form" :disabled="!canSubmit">
                {{ isEdit ? t('Save changes') : t('Add program') }}
            </Button>
        </template>
    </SidePanel>
</template>
