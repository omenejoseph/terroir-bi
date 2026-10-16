<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { qty } from '@/lib/cellar';
import type { MapVessel } from '@/types/cellar';

/** Take wine out of the selected vessels — one row at a time, or all of it. The litres stay on the lot as unallocated. */
const props = defineProps<{ open: boolean; vessels: MapVessel[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const rows = computed(() =>
    props.vessels.flatMap((v) => (v.lots ?? []).map((l) => ({ vessel: v.name, lotId: l.wine_lot_id, vesselLotId: l.vessel_lot_id, lot: l.lot?.name ?? '—', volume: l.volume }))),
);

function remove(lotId: string, vesselLotId: string): void {
    router.delete(`/cellar/lots/${lotId}/vessels/${vesselLotId}`, { preserveScroll: true });
}

async function removeAll(): Promise<void> {
    const ok = await confirmDialog({
        title: t('Take the wine out of :n vessels?', { n: props.vessels.length }),
        description: t('The litres stay on their lots as unallocated wine.'),
        confirmLabel: t('Remove all'),
        tone: 'danger',
    });
    if (!ok) return;
    // One visit at a time: Inertia cancels a visit when the next one starts.
    const queue = [...rows.value];
    const next = (): void => {
        const r = queue.shift();
        if (!r) {
            emit('close');

            return;
        }
        router.delete(`/cellar/lots/${r.lotId}/vessels/${r.vesselLotId}`, { preserveScroll: true, onSuccess: next });
    };
    next();
}
</script>

<template>
    <SidePanel :open="open" :title="t('Unassign wine')" @close="emit('close')">
        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">{{ t('These vessels are empty.') }}</p>
        <div v-for="r in rows" :key="r.vesselLotId" class="flex items-center justify-between gap-2 border-b border-border py-2 text-sm">
            <div>
                <p class="font-medium">{{ r.vessel }}</p>
                <p class="text-xs text-muted-foreground">{{ r.lot }} · {{ qty(r.volume) }} L</p>
            </div>
            <button type="button" class="p-1.5 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="remove(r.lotId, r.vesselLotId)">
                <Trash2 class="size-4" />
            </button>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Close') }}</Button>
            <Button v-if="rows.length" variant="destructive" size="lg" @click="removeAll">{{ t('Remove all') }}</Button>
        </template>
    </SidePanel>
</template>
