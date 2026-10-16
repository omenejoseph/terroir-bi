<script setup lang="ts">
import HistoryList from '@/components/cellar/HistoryList.vue';
import Card from '@/components/ui/Card.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { qty } from '@/lib/cellar';
import type { HistoryEntry, TraceNode, WineLotDetail } from '@/types/cellar';

/** The lot's own facts, where it came from (harvest, blends, vessels, bottlings) and what was done to its record. */
defineProps<{ lot: WineLotDetail; trace?: TraceNode; history?: HistoryEntry[] }>();

const { t } = useTranslations();
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <Card class="flex flex-col gap-1.5 p-4 text-xs">
            <SectionHeader :title="t('Details')" />
            <div class="mt-1 flex justify-between"><span class="text-muted-foreground">{{ t('Lot number') }}</span><span>{{ lot.lot_number }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape') }}</span><span>{{ lot.grape_variety }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Vintage') }}</span><span>{{ lot.vintage ?? '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Vineyard') }}</span><span>{{ lot.vineyard ?? '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Initial volume') }}</span><span>{{ qty(lot.initial_volume) }} L</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Current volume') }}</span><span>{{ qty(lot.current_volume) }} L</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Harvest weight') }}</span><span>{{ lot.harvest_weight_kg ? `${qty(lot.harvest_weight_kg)} kg` : '—' }}</span></div>
            <div v-if="lot.harvest_weight_kg && Number(lot.harvest_weight_kg) > 0" class="flex justify-between">
                <span class="text-muted-foreground">{{ t('Yield') }}</span><span>{{ qty((Number(lot.initial_volume) / Number(lot.harvest_weight_kg)) * 100) }} L / 100 kg</span>
            </div>
            <p v-if="lot.notes" class="mt-2 whitespace-pre-line">{{ lot.notes }}</p>
        </Card>

        <Card class="flex flex-col gap-1 p-4 text-xs">
            <SectionHeader :title="t('Traceability')" />
            <p v-if="trace === undefined" class="mt-2 text-muted-foreground">{{ t('Loading…') }}</p>
            <template v-else>
                <p class="mt-2 font-medium">{{ t('Harvest') }}</p>
                <p v-for="h in trace.harvest" :key="h.id" class="text-muted-foreground">{{ h.date }} · {{ h.parcel?.name ?? '—' }} · {{ h.grape_variety }} · {{ qty(h.kg) }} kg<template v-if="h.grower"> · {{ h.grower }}</template></p>
                <p v-if="trace.harvest.length === 0" class="text-muted-foreground">—</p>
                <p class="mt-2 font-medium">{{ t('Blended from') }}</p>
                <p v-for="(s, i) in trace.blended_from" :key="i" class="text-muted-foreground">{{ s.lot?.name }} ({{ s.lot?.lot_number }}) · {{ qty(s.volume) }} L</p>
                <p v-if="trace.blended_from.length === 0" class="text-muted-foreground">—</p>
                <p class="mt-2 font-medium">{{ t('Vessels') }}</p>
                <p class="text-muted-foreground">{{ trace.vessels.map((v) => v.name).join(', ') || '—' }}</p>
                <p class="mt-2 font-medium">{{ t('Bottlings') }}</p>
                <p v-for="b in trace.bottlings" :key="b.id" class="text-muted-foreground">{{ b.date }} · {{ b.bottles }} × {{ b.bottle_volume_ml }} ml<template v-if="b.item"> → {{ b.item.name }}</template></p>
                <p v-if="trace.bottlings.length === 0" class="text-muted-foreground">—</p>
            </template>
        </Card>

        <Card class="p-4 text-xs lg:col-span-2">
            <SectionHeader :title="t('History')" />
            <div class="mt-2"><HistoryList :entries="history" /></div>
        </Card>
    </div>
</template>
