<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { VESSEL_TYPE_OPTIONS } from '@/lib/cellar';

/** Quick-add vessels: a numbered run (prefix + start number × count), placed later from the map's Unplaced list. */
const props = defineProps<{ open: boolean; room: string }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({ prefix: 'B', start_number: 1, count: 10, type: 'BARRIQUE', material: '', capacity_liters: '225', room: '' });

watch(() => props.open, (open) => {
    if (open) form.room = props.room;
});

const preview = computed(() => {
    const n = Math.max(0, Math.min(50, Number(form.count) || 0));
    const start = Number(form.start_number) || 0;
    const names = Array.from({ length: Math.min(n, 4) }, (_, i) => `${form.prefix}${start + i}`);

    return n > 4 ? `${names.join(', ')} … ${form.prefix}${start + n - 1}` : names.join(', ');
});

function submit(): void {
    form.post('/cellar/vessels/bulk', { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Quick add vessels')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Name prefix')" :error="form.errors.prefix"><Input :id="id" :invalid="invalid" v-model="form.prefix" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Start number')" :error="form.errors.start_number"><Input :id="id" :invalid="invalid" v-model="form.start_number" inputmode="numeric" /></FormField>
            </FieldRow>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('How many (1–50)')" :error="form.errors.count"><Input :id="id" :invalid="invalid" v-model="form.count" inputmode="numeric" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Type')" :error="form.errors.type"><Select :id="id" :invalid="invalid" v-model="form.type" :options="VESSEL_TYPE_OPTIONS.map((o) => ({ ...o, label: t(o.label) }))" /></FormField>
            </FieldRow>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Material')" :error="form.errors.material"><Input :id="id" :invalid="invalid" v-model="form.material" :placeholder="t('Oak, inox…')" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Capacity (L)')" :error="form.errors.capacity_liters"><Input :id="id" :invalid="invalid" v-model="form.capacity_liters" inputmode="decimal" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id, invalid }" :label="t('Room')" :error="form.errors.room"><Input :id="id" :invalid="invalid" v-model="form.room" /></FormField>
            <p class="bg-muted px-3 py-2 text-xs text-muted-foreground">{{ t('Will create') }}: <span class="font-medium text-foreground">{{ preview }}</span></p>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Add :n vessels', { n: form.count }) }}</Button>
        </template>
    </SidePanel>
</template>
