<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import { csrfHeader } from '@/lib/csrf';

/**
 * Deleting a lot can't be undone, so the sheet says what goes with it —
 * fetched from the server when it opens — and asks for the lot number
 * before the button works.
 */
const props = defineProps<{ open: boolean; lotId: string; lotName: string }>();
const emit = defineEmits<{ close: [] }>();
const { t } = useTranslations();

interface Impact {
    lot_number: string;
    vessels: string[];
    bottlings: number;
    bottles: number;
    additions: number;
    analyses: number;
    tastings: number;
    processes: number;
    transfers: number;
}

const impact = ref<Impact | null>(null);
const failed = ref(false);
const typed = ref('');
const busy = ref(false);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        typed.value = '';
        failed.value = false;
        impact.value = null;
        try {
            const res = await fetch(`/cellar/lots/${props.lotId}/delete-impact`, { headers: { Accept: 'application/json', ...csrfHeader() }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(String(res.status));
            impact.value = (await res.json()) as Impact;
        } catch {
            failed.value = true;
        }
    },
);

const lines = computed(() => {
    const i = impact.value;
    if (!i) return [];
    const out: string[] = [];
    if (i.vessels.length) out.push(t('Emptied: :list.', { list: i.vessels.join(', ') }));
    if (i.bottlings) out.push(t(':n bottlings are reversed — :b bottles leave stock.', { n: i.bottlings, b: i.bottles }));
    const records = [
        [i.additions, t('additions')],
        [i.analyses, t('readings')],
        [i.tastings, t('tasting notes')],
        [i.processes, t('processes')],
        [i.transfers, t('transfers')],
    ].filter(([n]) => Number(n) > 0).map(([n, label]) => `${n} ${label}`);
    if (records.length) out.push(t('Removed: :list.', { list: records.join(', ') }));

    return out;
});

const matches = computed(() => impact.value !== null && typed.value.trim() === impact.value.lot_number);

function confirm(): void {
    if (!matches.value) return;
    busy.value = true;
    router.delete(`/cellar/lots/${props.lotId}`, { onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Dialog :open="open" :title="t('Delete :name?', { name: lotName })" @close="emit('close')">
        <div class="flex flex-col gap-3 text-sm">
            <p v-if="failed" class="text-destructive">{{ t('Could not load what this deletes.') }}</p>
            <p v-else-if="!impact" class="text-muted-foreground">{{ t('Loading…') }}</p>
            <template v-else>
                <ul class="flex flex-col gap-1.5">
                    <li v-for="l in lines" :key="l" class="flex gap-2"><span class="mt-1.5 size-1.5 shrink-0 bg-destructive" />{{ l }}</li>
                </ul>
                <p class="font-medium text-foreground">{{ t('This cannot be undone.') }}</p>
                <label class="flex flex-col gap-1.5">
                    <span class="text-xs text-muted-foreground">{{ t('Type the lot number :n to confirm', { n: impact.lot_number }) }}</span>
                    <Input v-model="typed" autocomplete="off" data-confirm-lot @keydown.enter.prevent="confirm" />
                </label>
            </template>
        </div>
        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button variant="destructive" :disabled="!matches || busy" @click="confirm">{{ t('Delete lot') }}</Button>
        </template>
    </Dialog>
</template>
