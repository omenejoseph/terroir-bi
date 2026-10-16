<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { WINE_TYPE_OPTIONS, parseDecimal } from '@/lib/cellar';
import type { FermentationTemplate, ProductOption } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * New / edit / duplicate a fermentation protocol: the headline settings,
 * stages by day (each with its tasks — they become board cards on the day),
 * and additive doses (per L, hL, kg or 100 kg — what "Plan protocol
 * additions" turns into planned work).
 */
const props = defineProps<{ open: boolean; template: FermentationTemplate | null; duplicate?: boolean; products: ProductOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

interface Stage {
    name: string;
    day_start: string;
    day_end: string;
    actions: string;
}
interface Additive {
    name: string;
    enological_product_id: string | null;
    dose: string;
    basis: string;
    stage: string;
    notes: string;
}

const form = useForm({
    name: '',
    wine_type: '',
    yeast_strain: '',
    target_temp_min: '',
    target_temp_max: '',
    punchdown_schedule: '',
    maceration: '',
    nutrients: '',
    mlf: false,
    description: '',
    estimated_duration: '',
    is_active: true,
    stages: [] as Stage[],
    additives: [] as Additive[],
});

/** Quick starts — a sensible skeleton to edit rather than a blank page. */
const QUICK: Record<string, { wine_type: string; temp: [string, string]; stages: Stage[] }> = {
    red: {
        wine_type: 'RED',
        temp: ['24', '28'],
        stages: [
            { name: 'Cold soak', day_start: '0', day_end: '2', actions: 'Punch-down twice a day' },
            { name: 'Alcoholic fermentation', day_start: '3', day_end: '12', actions: 'Pump-over\nMeasure density and temperature' },
            { name: 'Pressing', day_start: '13', day_end: '14', actions: 'Press and rack' },
        ],
    },
    white: {
        wine_type: 'WHITE',
        temp: ['14', '18'],
        stages: [
            { name: 'Settling', day_start: '0', day_end: '1', actions: 'Rack off the lees' },
            { name: 'Alcoholic fermentation', day_start: '2', day_end: '18', actions: 'Measure density and temperature' },
        ],
    },
};

function quickStart(key: string): void {
    const q = QUICK[key];
    if (!q) return;
    form.wine_type = q.wine_type;
    form.target_temp_min = q.temp[0];
    form.target_temp_max = q.temp[1];
    form.stages = q.stages.map((s) => ({ ...s, name: t(s.name), actions: s.actions.split('\n').map((a) => t(a)).join('\n') }));
}

watch(
    () => [props.open, props.template?.id, props.duplicate],
    () => {
        if (!props.open) return;
        const tp = props.template;
        form.defaults({
            name: tp ? (props.duplicate ? t(':name (copy)', { name: tp.name }) : tp.name) : '',
            wine_type: tp?.wine_type ?? '',
            yeast_strain: tp?.yeast_strain ?? '',
            target_temp_min: tp?.target_temp_min ?? '',
            target_temp_max: tp?.target_temp_max ?? '',
            punchdown_schedule: tp?.punchdown_schedule ?? '',
            maceration: tp?.maceration ?? '',
            nutrients: tp?.nutrients ?? '',
            mlf: tp?.mlf ?? false,
            description: tp?.description ?? '',
            estimated_duration: tp?.estimated_duration !== null && tp?.estimated_duration !== undefined ? String(tp.estimated_duration) : '',
            is_active: tp?.is_active ?? true,
            stages: (tp?.stages ?? []).map((s) => ({
                name: s.name,
                day_start: String(s.day_start ?? s.dayStart ?? 0),
                day_end: String(s.day_end ?? s.dayEnd ?? 0),
                actions: (s.actions ?? []).map((a) => a.description ?? a.type ?? '').join('\n'),
            })),
            additives: (tp?.additives ?? []).map((a) => ({ name: a.name, enological_product_id: a.enological_product_id, dose: a.dose, basis: a.basis, stage: a.stage ?? '', notes: a.notes ?? '' })),
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const productOptions = computed<ComboboxOption[]>(() => props.products.map((p) => ({ value: p.id, label: p.name, description: p.unit })));
const BASES = computed(() => [
    { value: 'per_hL', label: t('per hL of wine') },
    { value: 'per_L', label: t('per L of wine') },
    { value: 'per_kg', label: t('per kg of grapes') },
    { value: 'per_100kg', label: t('per 100 kg of grapes') },
]);

function pickProduct(i: number, id: string | null): void {
    const a = form.additives[i]!;
    a.enological_product_id = id;
    const p = props.products.find((x) => x.id === id);
    if (p && a.name === '') a.name = p.name;
}

const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        name: d.name,
        wine_type: d.wine_type || null,
        yeast_strain: d.yeast_strain || null,
        target_temp_min: parseDecimal(d.target_temp_min),
        target_temp_max: parseDecimal(d.target_temp_max),
        punchdown_schedule: d.punchdown_schedule || null,
        maceration: d.maceration || null,
        nutrients: d.nutrients || null,
        mlf: d.mlf,
        description: d.description || null,
        estimated_duration: d.estimated_duration === '' ? null : Number(d.estimated_duration),
        is_active: d.is_active,
        stages: d.stages
            .filter((s) => s.name.trim() !== '')
            .map((s) => ({
                name: s.name.trim(),
                day_start: Number(s.day_start) || 0,
                day_end: Number(s.day_end) || 0,
                actions: s.actions.split('\n').map((x) => x.trim()).filter(Boolean).map((description) => ({ type: 'TASK', description })),
            })),
        additives: d.additives.map((a) => ({ name: a.name, enological_product_id: a.enological_product_id, dose: parseDecimal(a.dose), basis: a.basis, stage: a.stage || null, notes: a.notes || null })),
    }));
    if (props.template && !props.duplicate) form.patch(`/cellar/protocols/${props.template.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post('/cellar/protocols', { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="template && !duplicate ? t('Edit protocol') : t('New protocol')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <div v-if="!template" class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-muted-foreground">{{ t('Quick start') }}:</span>
                <Button size="sm" variant="outline" @click="quickStart('red')">{{ t('Red') }}</Button>
                <Button size="sm" variant="outline" @click="quickStart('white')">{{ t('White') }}</Button>
            </div>
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name"><Input :id="id" v-model="form.name" :invalid="invalid" /></FormField>
            <FieldRow>
                <FormField v-slot="{ id }" :label="t('Wine type')"><Select :id="id" v-model="form.wine_type" :placeholder="t('Any')" :options="WINE_TYPE_OPTIONS.map((o) => ({ ...o, label: t(o.label) }))" /></FormField>
                <FormField v-slot="{ id }" :label="t('Yeast strain')"><Input :id="id" v-model="form.yeast_strain" /></FormField>
            </FieldRow>
            <div class="grid grid-cols-3 gap-3">
                <FormField v-slot="{ id }" :label="t('Temp. min (°C)')"><Input :id="id" v-model="form.target_temp_min" inputmode="decimal" /></FormField>
                <FormField v-slot="{ id }" :label="t('Temp. max (°C)')"><Input :id="id" v-model="form.target_temp_max" inputmode="decimal" /></FormField>
                <FormField v-slot="{ id }" :label="t('Duration (days)')"><Input :id="id" v-model="form.estimated_duration" inputmode="numeric" /></FormField>
            </div>
            <FieldRow>
                <FormField v-slot="{ id }" :label="t('Punch-down schedule')"><Input :id="id" v-model="form.punchdown_schedule" /></FormField>
                <FormField v-slot="{ id }" :label="t('Maceration')"><Input :id="id" v-model="form.maceration" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id }" :label="t('Nutrients')"><Input :id="id" v-model="form.nutrients" /></FormField>
            <SwitchRow v-model="form.mlf" :label="t('Malolactic fermentation')" />
            <FormField v-slot="{ id }" :label="t('Description')"><Textarea :id="id" v-model="form.description" :rows="2" /></FormField>

            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ t('Stages') }}</span>
                    <button type="button" class="inline-flex items-center gap-1 text-xs hover:underline" @click="form.stages.push({ name: '', day_start: '0', day_end: '0', actions: '' })"><Plus class="size-3" /> {{ t('Add stage') }}</button>
                </div>
                <div v-for="(s, i) in form.stages" :key="i" class="flex flex-col gap-2 border border-border p-3">
                    <div class="grid grid-cols-[1fr_64px_64px_auto] items-end gap-2">
                        <FormField v-slot="{ id }" :label="t('Stage')"><Input :id="id" v-model="s.name" /></FormField>
                        <FormField v-slot="{ id }" :label="t('From day')"><Input :id="id" v-model="s.day_start" inputmode="numeric" /></FormField>
                        <FormField v-slot="{ id }" :label="t('To day')"><Input :id="id" v-model="s.day_end" inputmode="numeric" /></FormField>
                        <button type="button" class="mb-2 p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="form.stages.splice(i, 1)"><Trash2 class="size-4" /></button>
                    </div>
                    <FormField v-slot="{ id }" :label="t('Tasks (one per line)')"><Textarea :id="id" v-model="s.actions" :rows="2" /></FormField>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ t('Additive doses') }}</span>
                    <button type="button" class="inline-flex items-center gap-1 text-xs hover:underline" @click="form.additives.push({ name: '', enological_product_id: null, dose: '', basis: 'per_hL', stage: '', notes: '' })"><Plus class="size-3" /> {{ t('Add additive') }}</button>
                </div>
                <div v-for="(a, i) in form.additives" :key="i" class="flex flex-col gap-2 border border-border p-3">
                    <div class="flex items-center justify-between gap-2">
                        <FormField v-slot="{ id }" :label="t('Product from stock')" class="flex-1">
                            <Combobox :id="id" :model-value="a.enological_product_id" :options="productOptions" clearable @update:model-value="pickProduct(i, $event)" />
                        </FormField>
                        <button type="button" class="mt-5 p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="form.additives.splice(i, 1)"><Trash2 class="size-4" /></button>
                    </div>
                    <FieldRow>
                        <FormField v-slot="{ id, invalid }" :label="t('Name')" :error="err(`additives.${i}.name`)"><Input :id="id" v-model="a.name" :invalid="invalid" /></FormField>
                        <FormField v-slot="{ id }" :label="t('Stage')"><Input :id="id" v-model="a.stage" /></FormField>
                    </FieldRow>
                    <FieldRow>
                        <FormField v-slot="{ id, invalid }" :label="t('Dose')" :error="err(`additives.${i}.dose`)"><Input :id="id" v-model="a.dose" inputmode="decimal" :invalid="invalid" /></FormField>
                        <FormField v-slot="{ id }" :label="t('Per')"><Select :id="id" v-model="a.basis" :options="BASES" /></FormField>
                    </FieldRow>
                </div>
            </div>
            <SwitchRow v-model="form.is_active" :label="t('Active')" />
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
