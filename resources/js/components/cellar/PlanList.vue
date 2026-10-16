<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CalendarDays, Check, Trash2, X } from 'lucide-vue-next';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { ACTIVITY_LABELS, METRIC_LABELS, SKIP_REASON_LABELS, activityTitle, dayLabel, describeActivity } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { CellarActivity, PlanDay, PlanRound } from '@/types/cellar';

/**
 * The plan drawer's two lists. `open` lists PLANNED work by day → round with
 * move / delete; `executed` lists what was done or skipped, by when it was
 * done, with who did it and where it deviated from the plan.
 */
const props = defineProps<{
    days: PlanDay[];
    mode: 'open' | 'executed';
    locale: string;
    canManage: boolean;
    vesselName: (id: string) => string | undefined;
}>();

const { t } = useTranslations();

const movingRound = ref<string | null>(null);
const moveDate = ref('');
const moveReason = ref('');

function describe(a: CellarActivity): string {
    return describeActivity(a, t, props.vesselName);
}

function chipLabel(a: CellarActivity): string {
    const lot = a.vessel && a.wine_lot ? ` ${a.wine_lot.name}${a.wine_lot.vintage ? ` ${a.wine_lot.vintage}` : ''}` : '';

    return `${activityTitle(a)}${lot}`;
}

function startMove(round: PlanRound): void {
    movingRound.value = movingRound.value === round.id ? null : round.id;
    moveDate.value = '';
    moveReason.value = '';
}

function moveRound(round: PlanRound): void {
    if (moveDate.value === '') return;
    router.post(
        `/cellar/activity-rounds/${round.id}/reschedule`,
        { planned_for: moveDate.value, reason: moveReason.value.trim() || null },
        { preserveScroll: true, onSuccess: () => (movingRound.value = null) },
    );
}

async function deleteRound(round: PlanRound): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete this round?'),
        description: t('Its :n open tasks are removed and its board card is closed.', { n: round.items.length }),
        confirmLabel: t('Delete round'),
        tone: 'danger',
    });
    if (ok) router.delete(`/cellar/activity-rounds/${round.id}`, { preserveScroll: true });
}

async function removeItem(a: CellarActivity): Promise<void> {
    const ok = await confirmDialog({ title: t('Remove this task?'), description: `${chipLabel(a)} — ${describe(a)}`, confirmLabel: t('Remove'), tone: 'danger' });
    if (ok) router.delete(`/cellar/activities/${a.id}`, { preserveScroll: true });
}

/** The free SO₂ that was measured, shown on executed reading chips. */
function measured(a: CellarActivity): string | null {
    const v = a.payload.applied?.values?.free_so2;

    return v === undefined ? null : `${t(METRIC_LABELS.free_so2)} ${v}`;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="days.length === 0" class="py-6 text-center text-xs text-muted-foreground">
            {{
                mode === 'open'
                    ? t('Nothing planned. Plan work above — the team sees it instantly on the Today screen.')
                    : t('Nothing executed yet.')
            }}
        </p>

        <section v-for="day in days" :key="day.date" class="flex flex-col gap-2">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ dayLabel(day.date, locale) }}</h4>

            <div v-for="round in day.rounds" :key="round.id" class="border border-border bg-card">
                <div class="flex items-center gap-2 border-b border-border px-3 py-2">
                    <Check v-if="mode === 'executed'" class="size-4 shrink-0 text-success" />
                    <span class="truncate text-sm font-medium text-foreground">{{ round.name ?? t('Round') }}</span>
                    <Badge v-if="mode === 'open'">{{ round.items.length }}</Badge>
                    <template v-else>
                        <Badge variant="success">{{ round.done }} ✓</Badge>
                        <Badge v-if="round.skipped" variant="neutral">{{ t(':n skipped', { n: round.skipped }) }}</Badge>
                    </template>
                    <span v-if="mode === 'open' && round.items[0]" class="hidden min-w-0 truncate text-xs text-muted-foreground sm:inline">
                        {{ t(ACTIVITY_LABELS[round.items[0].type]) }} · {{ describe(round.items[0]) }}
                    </span>
                    <span v-if="mode === 'executed'" class="ml-auto hidden truncate text-xs text-muted-foreground sm:inline">{{ round.executed_by.join(', ') }}</span>
                    <div v-if="mode === 'open' && canManage" class="ml-auto flex shrink-0 gap-1">
                        <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground" :aria-label="t('Move round')" @click="startMove(round)">
                            <CalendarDays class="size-4" />
                        </button>
                        <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent hover:text-destructive" :aria-label="t('Delete round')" @click="deleteRound(round)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>
                <div v-if="movingRound === round.id" class="flex flex-wrap items-center gap-2 border-b border-border bg-muted/50 px-3 py-2">
                    <Input v-model="moveDate" type="date" class="w-40" />
                    <Input v-model="moveReason" class="min-w-40 flex-1" :placeholder="t('Reason (optional) — e.g. harvest, no time')" />
                    <Button size="sm" :disabled="moveDate === ''" @click="moveRound(round)">{{ t('Move round') }}</Button>
                </div>
                <div class="flex flex-wrap gap-1.5 p-2">
                    <span
                        v-for="a in round.items"
                        :key="a.id"
                        :class="
                            cn(
                                'inline-flex items-center gap-1 border border-border px-2 py-0.5 text-xs',
                                mode === 'executed' && a.status !== 'DONE' && 'opacity-50',
                            )
                        "
                    >
                        {{ chipLabel(a) }}
                        <span v-if="mode === 'executed' && measured(a)" class="text-muted-foreground">· {{ measured(a) }}</span>
                        <span v-if="a.deviated" class="text-tag-orange-foreground" :title="t('adjusted')">Δ</span>
                        <button
                            v-if="mode === 'open' && canManage"
                            type="button"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="t('Remove from round')"
                            @click="removeItem(a)"
                        >
                            <X class="size-3" />
                        </button>
                    </span>
                </div>
            </div>

            <div v-if="day.singles.length" class="border border-border bg-card">
                <div v-for="a in day.singles" :key="a.id" class="flex items-center gap-2 border-b border-border px-3 py-1.5 text-xs last:border-0">
                    <template v-if="mode === 'executed'">
                        <Check v-if="a.status === 'DONE'" class="size-3.5 shrink-0 text-success" />
                        <X v-else class="size-3.5 shrink-0 text-muted-foreground" />
                    </template>
                    <span class="w-32 shrink-0 truncate font-medium text-foreground">{{ activityTitle(a) }}</span>
                    <span class="shrink-0 text-muted-foreground">{{ t(ACTIVITY_LABELS[a.type]) }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ describe(a) }}</span>
                    <span v-if="a.deviated" class="shrink-0 bg-tag-orange px-1.5 text-[10px] text-tag-orange-foreground">{{ t('adjusted') }}</span>
                    <span v-if="a.status === 'SKIPPED' && a.skip_reason" class="shrink-0 text-muted-foreground">{{ t(SKIP_REASON_LABELS[a.skip_reason] ?? a.skip_reason) }}</span>
                    <span v-if="mode === 'open' && a.wine_lot" class="hidden shrink-0 text-muted-foreground sm:inline">{{ a.wine_lot.name }}</span>
                    <span v-if="mode === 'executed' && a.executed_by" class="hidden shrink-0 text-muted-foreground sm:inline">{{ a.executed_by.name }}</span>
                    <button
                        v-if="mode === 'open' && canManage"
                        type="button"
                        class="shrink-0 p-1 text-muted-foreground hover:text-destructive"
                        :aria-label="t('Remove')"
                        @click="removeItem(a)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
