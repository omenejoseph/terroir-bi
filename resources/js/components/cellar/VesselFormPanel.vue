<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { VESSEL_TYPE_OPTIONS } from '@/lib/cellar';
import type { Vessel } from '@/types/cellar';

/** New / edit vessel. Room suggests the rooms already in use (a datalist), so a typo doesn't start a new room. */
const props = defineProps<{ open: boolean; vessel: Vessel | null; rooms: string[]; defaultRoom?: string }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({ name: '', type: 'TANK', material: '', capacity_liters: '', room: '', location: '', notes: '' });

watch(
    () => [props.open, props.vessel?.id],
    () => {
        if (!props.open) return;
        const v = props.vessel;
        form.defaults({
            name: v?.name ?? '',
            type: v?.type ?? 'TANK',
            material: v?.material ?? '',
            capacity_liters: v?.capacity_liters ?? '',
            room: v?.room ?? props.defaultRoom ?? '',
            location: v?.location ?? '',
            notes: v?.notes ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    form.transform((d) => ({ ...d, material: d.material || null, room: d.room || null, location: d.location || null, notes: d.notes || null }));
    if (props.vessel) form.patch(`/cellar/vessels/${props.vessel.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post('/cellar/vessels', { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="vessel ? t('Edit vessel') : t('New vessel')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name"><Input :id="id" :invalid="invalid" v-model="form.name" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Type')" required :error="form.errors.type">
                    <Select :id="id" :invalid="invalid" v-model="form.type" :options="VESSEL_TYPE_OPTIONS.map((o) => ({ ...o, label: t(o.label) }))" />
                </FormField>
            </FieldRow>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Material')" :error="form.errors.material"><Input :id="id" :invalid="invalid" v-model="form.material" :placeholder="t('Oak, inox…')" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Capacity (L)')" required :error="form.errors.capacity_liters"><Input :id="id" :invalid="invalid" v-model="form.capacity_liters" inputmode="decimal" /></FormField>
            </FieldRow>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Room')" :error="form.errors.room">
                    <Input :id="id" :invalid="invalid" v-model="form.room" list="cellar-rooms" />
                    <datalist id="cellar-rooms"><option v-for="r in rooms" :key="r" :value="r" /></datalist>
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Location')" :error="form.errors.location"><Input :id="id" :invalid="invalid" v-model="form.location" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id, invalid }" :label="t('Notes')" :error="form.errors.notes"><Textarea :id="id" :invalid="invalid" v-model="form.notes" :rows="3" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ vessel ? t('Save') : t('Create vessel') }}</Button>
        </template>
    </SidePanel>
</template>
