<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { TastingReport } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * New / edit tasting report: title, date, an overall note, and the wines
 * tasted — each with a score /100 and notes. Saving keeps the lots' tasting
 * notes in step (added, changed, or removed with the wine).
 */
const props = defineProps<{ open: boolean; report: TastingReport | null; lots: ComboboxOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

interface Wine {
    wine_lot_id: string;
    label: string;
    score: string;
    nose: string;
    palate: string;
    note: string;
}

const form = useForm({ title: '', date: '', note: '', wines: [] as Wine[] });
const adding = ref<string | null>(null);

watch(
    () => [props.open, props.report?.id],
    () => {
        if (!props.open) return;
        const r = props.report;
        form.defaults({
            title: r?.title ?? '',
            date: (r?.date ?? new Date().toISOString()).slice(0, 10),
            note: r?.note ?? '',
            wines: (r?.wines ?? []).filter((w) => w.wine_lot).map((w) => ({
                wine_lot_id: w.wine_lot!.id,
                label: [w.wine_lot!.name, w.wine_lot!.vintage].filter(Boolean).join(' '),
                score: w.score !== null ? String(w.score) : '',
                nose: w.nose ?? '',
                palate: w.palate ?? '',
                note: w.note ?? '',
            })),
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const available = computed(() => props.lots.filter((l) => !form.wines.some((w) => w.wine_lot_id === l.value)));
watch(adding, (id) => {
    if (!id) return;
    const lot = props.lots.find((l) => l.value === id);
    if (lot) form.wines.push({ wine_lot_id: id, label: lot.label, score: '', nose: '', palate: '', note: '' });
    adding.value = null;
});

const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        title: d.title || null,
        date: d.date || null,
        note: d.note || null,
        wines: d.wines.map((w) => ({ wine_lot_id: w.wine_lot_id, score: w.score === '' ? null : Number(w.score), nose: w.nose || null, palate: w.palate || null, note: w.note || null })),
    }));
    if (props.report) form.patch(`/cellar/tastings/${props.report.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post('/cellar/tastings', { onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="report ? t('Edit tasting report') : t('New tasting report')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Title')" :error="form.errors.title"><Input :id="id" v-model="form.title" :invalid="invalid" :placeholder="t('e.g. October panel')" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Date')" :error="form.errors.date"><Input :id="id" v-model="form.date" type="date" :invalid="invalid" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id }" :label="t('Overall note')" :error="form.errors.note"><Textarea :id="id" v-model="form.note" :rows="2" /></FormField>

            <FormField v-slot="{ id }" :label="t('Wines tasted')" :error="err('wines')">
                <Combobox :id="id" v-model="adding" :options="available" :placeholder="t('Search a wine to add…')" />
            </FormField>
            <div v-for="(w, i) in form.wines" :key="w.wine_lot_id" class="flex flex-col gap-2 border border-border p-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ w.label }}</span>
                    <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="form.wines.splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <div class="grid grid-cols-[80px_1fr] gap-2">
                    <FormField v-slot="{ id, invalid }" :label="t('Score')" :error="err(`wines.${i}.score`)"><Input :id="id" v-model="w.score" inputmode="numeric" :invalid="invalid" placeholder="/100" /></FormField>
                    <FormField v-slot="{ id }" :label="t('Note')"><Input :id="id" v-model="w.note" /></FormField>
                </div>
                <FieldRow>
                    <FormField v-slot="{ id }" :label="t('Nose')"><Input :id="id" v-model="w.nose" /></FormField>
                    <FormField v-slot="{ id }" :label="t('Palate')"><Input :id="id" v-model="w.palate" /></FormField>
                </FieldRow>
            </div>
            <p v-if="form.wines.length === 0" class="text-xs text-muted-foreground">{{ t('Add the wines you tasted.') }}</p>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save report') }}</Button>
        </template>
    </SidePanel>
</template>
