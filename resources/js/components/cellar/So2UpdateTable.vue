<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, ChevronDown, ChevronRight, Search } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import { SO2_STATUS_TONES, parseDecimal, qty, so2Status } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { So2Lot } from '@/types/cellar';

/**
 * SO₂ › Update readings: type the lab's free / total SO₂ per lot, or per
 * vessel once a lot is expanded. Only filled cells are saved — one reading
 * per lot or vessel, through the same bulk-analyses Action as everywhere.
 */
const props = defineProps<{ lots: So2Lot[]; canManage: boolean }>();

const { t } = useTranslations();

const search = ref('');
const expanded = ref<string[]>([]);
const values = reactive<Record<string, { free: string; total: string }>>({});
const focused = ref<string | null>(null);
const saved = ref(false);
const busy = ref(false);

/** Every lot and vessel gets its two cells up front, so the template never adds keys while rendering. */
function reset(): void {
    for (const key of Object.keys(values)) delete values[key];
    for (const lot of props.lots) {
        values[lot.id] = { free: '', total: '' };
        for (const v of lot.vessels) values[`${lot.id}:${v.id}`] = { free: '', total: '' };
    }
}
watch(() => props.lots, reset, { immediate: true });

function cell(key: string): { free: string; total: string } {
    return values[key] ?? { free: '', total: '' };
}

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (q === '') return props.lots;

    return props.lots.filter((l) =>
        [l.name, l.lot_number, l.grape_variety ?? '', l.vintage ?? '', ...l.vessels.map((v) => v.name)].some((s) => s.toLowerCase().includes(q)),
    );
});

const rows = computed(() => {
    const out: { wine_lot_id: string; vessel_id: string | null; free_so2?: number; total_so2?: number }[] = [];
    for (const [key, v] of Object.entries(values)) {
        const free = parseDecimal(v.free);
        const total = parseDecimal(v.total);
        if (free === null && total === null) continue;
        const [lotId, vesselId] = key.split(':');
        if (vesselId === undefined && expanded.value.includes(lotId!)) continue;
        out.push({
            wine_lot_id: lotId!,
            vessel_id: vesselId ?? null,
            ...(free !== null ? { free_so2: free } : {}),
            ...(total !== null ? { total_so2: total } : {}),
        });
    }

    return out;
});

function toggle(lot: So2Lot): void {
    expanded.value = expanded.value.includes(lot.id) ? expanded.value.filter((id) => id !== lot.id) : [...expanded.value, lot.id];
}

function save(): void {
    if (rows.value.length === 0) return;
    busy.value = true;
    router.post(
        '/cellar/so2/readings',
        { analyses: rows.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                expanded.value = [];
                saved.value = true;
                setTimeout(() => (saved.value = false), 1500);
            },
            onFinish: () => (busy.value = false),
        },
    );
}

function ago(days: number | null): string {
    if (days === null) return '';
    if (days === 0) return t('today');

    return t(':nd', { n: days });
}
</script>

<template>
    <Card class="flex flex-col">
        <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold">{{ t('Quick free SO₂ entry') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('Enter free SO₂ (mg/L) from your lab results. Only filled values are saved.') }}</p>
            </div>
            <span v-if="saved" class="inline-flex items-center gap-1 text-xs text-success"><Check class="size-3.5" /> {{ t('Saved!') }}</span>
            <span v-if="rows.length" class="text-xs text-muted-foreground">{{ t(':n entered', { n: rows.length }) }}</span>
            <Button v-if="canManage" size="sm" :disabled="busy || rows.length === 0" @click="save">{{ t('Save (:n)', { n: rows.length }) }}</Button>
        </div>
        <div class="border-b border-border p-3">
            <div class="relative max-w-sm">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="search" class="pl-8" :placeholder="t('Search lots...')" />
            </div>
        </div>
        <div class="max-h-[600px] overflow-auto">
            <table class="w-full text-xs">
                <thead class="sticky top-0 z-10 bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">{{ t('Lot') }}</th>
                        <th class="hidden px-3 py-2 text-left font-medium sm:table-cell">{{ t('Vessels') }}</th>
                        <th class="hidden px-3 py-2 text-right font-medium md:table-cell">L</th>
                        <th class="px-3 py-2 text-right font-medium">{{ t('Prev. free') }}</th>
                        <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">{{ t('Prev. total') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ t('Free SO₂') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ t('Total SO₂') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="lot in visible" :key="lot.id">
                        <tr :class="cn('border-t border-border', focused === lot.id && 'bg-primary/5')">
                            <td class="px-3 py-2">
                                <button v-if="lot.vessels.length > 1" type="button" class="mr-1 inline-flex align-middle text-muted-foreground" :aria-label="t('Per vessel')" @click="toggle(lot)">
                                    <ChevronDown v-if="expanded.includes(lot.id)" class="size-3.5" />
                                    <ChevronRight v-else class="size-3.5" />
                                </button>
                                <span class="font-medium text-foreground">{{ lot.name }}</span>
                                <div class="text-[11px] text-muted-foreground">
                                    {{ [lot.grape_variety, lot.vintage].filter(Boolean).join(' ') }} ({{ t(':n vessels', { n: lot.vessels.length }) }})
                                </div>
                            </td>
                            <td class="hidden max-w-40 truncate px-3 py-2 text-muted-foreground sm:table-cell">{{ lot.vessels.map((v) => v.name).join(', ') }}</td>
                            <td class="hidden px-3 py-2 text-right tabular-nums md:table-cell">{{ qty(lot.current_volume) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <span :class="SO2_STATUS_TONES[lot.so2_status]">{{ lot.free_so2 ?? '—' }}</span>
                                <div class="text-[10px] text-muted-foreground">{{ ago(lot.days_since) }}</div>
                            </td>
                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ lot.total_so2 ?? '—' }}</td>
                            <template v-if="expanded.includes(lot.id)">
                                <td colspan="2" class="px-3 py-2 text-right text-[11px] text-muted-foreground">{{ t('Enter per vessel below') }}</td>
                            </template>
                            <template v-else>
                                <td class="px-2 py-1.5"><Input v-model="cell(lot.id).free" inputmode="decimal" class="ml-auto h-8 w-20 text-right" :disabled="!canManage" @focus="focused = lot.id" /></td>
                                <td class="px-2 py-1.5"><Input v-model="cell(lot.id).total" inputmode="decimal" class="ml-auto h-8 w-20 text-right" :disabled="!canManage" @focus="focused = lot.id" /></td>
                            </template>
                        </tr>
                        <template v-if="expanded.includes(lot.id)">
                            <tr v-for="v in lot.vessels" :key="v.id" :class="cn('border-t border-border/60 bg-muted/30', focused === `${lot.id}:${v.id}` && 'bg-primary/10')">
                                <td class="py-1.5 pl-9 pr-3">
                                    {{ v.name }} <span class="text-[11px] text-muted-foreground">{{ v.material ?? v.type }}</span>
                                </td>
                                <td class="hidden sm:table-cell" />
                                <td class="hidden px-3 text-right tabular-nums md:table-cell">{{ qty(v.volume) }}</td>
                                <td class="px-3 text-right tabular-nums">
                                    <span :class="SO2_STATUS_TONES[so2Status(v.inherited ? null : v.free_so2)]">{{ v.inherited ? '—' : (v.free_so2 ?? '—') }}</span>
                                </td>
                                <td class="hidden px-3 text-right tabular-nums sm:table-cell">{{ v.inherited ? '—' : (v.total_so2 ?? '—') }}</td>
                                <td class="px-2 py-1"><Input v-model="cell(`${lot.id}:${v.id}`).free" inputmode="decimal" class="ml-auto h-7 w-20 text-right" :disabled="!canManage" @focus="focused = `${lot.id}:${v.id}`" /></td>
                                <td class="px-2 py-1"><Input v-model="cell(`${lot.id}:${v.id}`).total" inputmode="decimal" class="ml-auto h-7 w-20 text-right" :disabled="!canManage" @focus="focused = `${lot.id}:${v.id}`" /></td>
                            </tr>
                        </template>
                    </template>
                    <tr v-if="visible.length === 0">
                        <td colspan="7" class="px-3 py-8 text-center text-muted-foreground">{{ t('No lots match the filters') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
