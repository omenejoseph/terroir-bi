<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import type { WineLotDetail } from '@/types/cellar';

/** Record a cellar operation (pump-over, bâtonnage, filtration…) on the lot or one of its vessels. */
const props = defineProps<{ open: boolean; lot: WineLotDetail; vesselId?: string | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const KINDS = ['Pump-over', 'Punch-down', 'Bâtonnage', 'Racking', 'Filtration', 'Fining', 'Cold stabilisation', 'Malolactic inoculation', 'Pressing'];

const form = useForm({ kind: '', vessel_id: '', date: '', volume: '', note: '' });

watch(() => props.open, (open) => {
    if (!open) return;
    form.defaults({ kind: '', vessel_id: props.vesselId ?? '', date: new Date().toISOString().slice(0, 10), volume: '', note: '' });
    form.reset();
    form.clearErrors();
});

const vesselOptions = computed(() => [{ value: '', label: t('Whole lot') }, ...props.lot.vessels.map((v) => ({ value: v.vessel_id, label: `${v.vessel_name ?? '—'} · ${qty(v.volume)} L` }))]);

function submit(): void {
    form.transform((d) => ({ kind: d.kind, vessel_id: d.vessel_id || null, date: d.date || null, volume: parseDecimal(d.volume), note: d.note || null }));
    form.post(`/cellar/lots/${props.lot.id}/processes`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="t('New process')" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FormField v-slot="{ id, invalid }" :label="t('Process')" required :error="form.errors.kind">
                <Input :id="id" :invalid="invalid" v-model="form.kind" list="process-kinds" />
                <datalist id="process-kinds"><option v-for="k in KINDS" :key="k" :value="t(k)" /></datalist>
            </FormField>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Vessel')" :error="form.errors.vessel_id"><Select :id="id" :invalid="invalid" v-model="form.vessel_id" :options="vesselOptions" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Date')" :error="form.errors.date"><Input :id="id" :invalid="invalid" v-model="form.date" type="date" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id, invalid }" :label="t('Litres (optional)')" :error="form.errors.volume"><Input :id="id" :invalid="invalid" v-model="form.volume" inputmode="decimal" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
