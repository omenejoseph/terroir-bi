<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import { csrfHeader } from '@/lib/csrf';
import type { LotOption, MapVessel } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * Put a lot's wine into the vessels selected on the map: wine already in the
 * lot but not in a vessel ("existing", capped at the unallocated litres) or
 * new wine that grows the lot; filling each vessel to capacity or splitting
 * equally. The preview is the server's own plan (a dry run).
 */
const props = defineProps<{ open: boolean; vessels: MapVessel[]; lots: LotOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const lotId = ref<string | null>(null);
const source = ref<'existing' | 'new'>('existing');
const mode = ref<'fill' | 'split'>('fill');
const volume = ref('');
const preview = ref<{ lines: { vessel_id: string; vessel_name: string; volume: string }[]; total: string; lot_grows_by: string } | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);

watch(() => props.open, (open) => {
    if (!open) return;
    lotId.value = null;
    preview.value = null;
    error.value = null;
    volume.value = '';
});

const lot = computed(() => props.lots.find((l) => l.id === lotId.value) ?? null);
const lotOptions = computed<ComboboxOption[]>(() =>
    props.lots.map((l) => ({
        value: l.id,
        label: [l.name, l.vintage].filter(Boolean).join(' '),
        description: t(':in L in vessels · :free L unallocated', { in: qty(Number(l.current_volume) - Number(l.unallocated)), free: qty(l.unallocated) }),
        keywords: [l.lot_number],
    })),
);

function body(dryRun: boolean): Record<string, unknown> {
    return {
        vessel_ids: props.vessels.map((v) => v.id),
        source: source.value,
        mode: mode.value,
        volume: parseDecimal(volume.value),
        dry_run: dryRun,
    };
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch([lotId, source, mode, volume, () => props.vessels], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(fetchPreview, 250);
});

async function fetchPreview(): Promise<void> {
    if (!props.open || lotId.value === null) {
        preview.value = null;

        return;
    }
    const res = await fetch(`/cellar/lots/${lotId.value}/vessels/bulk`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify(body(true)),
    });
    const json = await res.json();
    if (res.ok) {
        preview.value = json.data;
        error.value = null;
    } else {
        preview.value = null;
        error.value = Object.values((json.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? json.message ?? null;
    }
}

function submit(): void {
    if (lotId.value === null) return;
    busy.value = true;
    router.post(`/cellar/lots/${lotId.value}/vessels/bulk`, body(false) as never, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Assign wine')" :subtitle="vessels.map((v) => v.name).join(', ')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FormField v-slot="{ id, invalid }" :label="t('Wine lot')">
                <Combobox :id="id" :invalid="invalid" v-model="lotId" :options="lotOptions" :placeholder="t('Select lot…')" />
            </FormField>
            <div v-if="lot" class="grid grid-cols-2 gap-2 bg-muted px-3 py-2 text-xs">
                <span class="text-muted-foreground">{{ t('In vessels') }}</span>
                <span class="text-right tabular-nums">{{ qty(Number(lot.current_volume) - Number(lot.unallocated)) }} L</span>
                <span class="text-muted-foreground">{{ t('Unallocated') }}</span>
                <span class="text-right tabular-nums">{{ qty(lot.unallocated) }} L</span>
            </div>
            <FormField :label="t('Wine')">
                <Tabs
                    :items="[
                        { label: t('Assign existing wine'), value: 'existing' },
                        { label: t('Add new wine to lot'), value: 'new' },
                    ]"
                    :current="source"
                    full
                    @select="source = $event as 'existing' | 'new'"
                />
            </FormField>
            <FormField :label="t('How')">
                <Tabs
                    :items="[
                        { label: t('Fill to capacity'), value: 'fill' },
                        { label: t('Split equally'), value: 'split' },
                    ]"
                    :current="mode"
                    full
                    @select="mode = $event as 'fill' | 'split'"
                />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Litres (optional — defaults to all available)')">
                <Input :id="id" :invalid="invalid" v-model="volume" inputmode="decimal" />
            </FormField>
            <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>
            <div v-if="preview" class="border border-border text-xs">
                <p class="border-b border-border bg-muted px-3 py-1.5 font-medium">{{ t('Preview') }}</p>
                <div v-for="l in preview.lines" :key="l.vessel_id" class="flex justify-between border-b border-border px-3 py-1 last:border-0">
                    <span>{{ l.vessel_name }}</span><span class="tabular-nums">{{ qty(l.volume) }} L</span>
                </div>
                <div class="flex justify-between bg-muted px-3 py-1.5 font-medium">
                    <span>{{ t('Total') }}</span><span class="tabular-nums">{{ qty(preview.total) }} L</span>
                </div>
                <p v-if="Number(preview.lot_grows_by) > 0" class="px-3 py-1.5 text-muted-foreground">{{ t('The lot grows by :n L.', { n: qty(preview.lot_grows_by) }) }}</p>
            </div>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="busy || !preview" @click="submit">{{ t('Assign') }}</Button>
        </template>
    </SidePanel>
</template>
