<script setup lang="ts">
import { computed, nextTick, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, CloudOff, RefreshCw, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import TodayCard from '@/components/cellar/TodayCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useActivityQueue } from '@/composables/useActivityQueue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { SKIP_REASON_LABELS, activityTitle, describeActivity } from '@/lib/cellar';
import type { CellarToday } from '@/types/cellar';
import type { TabItem } from '@/types/ui';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar › Today — the cellar team works through the planned tasks, walking
 * the cellar room by room (each room lists its vessels in walking order).
 * Overdue work is included and flagged; what was done or skipped today stays
 * listed below so the day reads as a whole. Ticks are kept on the phone when
 * the signal drops and sent when it's back (see useActivityQueue).
 */
const props = defineProps<{ today: CellarToday; mine: boolean }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can, user } = useAuth();

const canManage = computed(() => can('cellar.manage'));
const meId = computed(() => user.value?.id ?? null);
const queue = useActivityQueue();

const errors = computed(() => Object.values(page.props.errors ?? {}));

const counts = computed(() => props.today.counts);
const subtitle = computed(() => {
    // What's left on screen — counts ticks made on this phone that haven't reached the server yet.
    const parts = [t(':n to do', { n: openCount.value })];
    if (counts.value.done_today > 0) parts.push(t(':n done', { n: counts.value.done_today }));

    return parts.join(' · ');
});

/** Only worth a toggle when "mine" would show something different. */
const showToggle = computed(() => meId.value !== null && counts.value.mine !== counts.value.open);
const scopeTabs = computed<TabItem[]>(() => [
    { label: t('Mine · :n', { n: counts.value.mine }), value: 'mine' },
    { label: t('Everyone · :n', { n: counts.value.open }), value: 'all' },
]);

function setScope(value: string): void {
    router.get('/cellar/today', value === 'mine' ? { mine: 1 } : {}, { preserveScroll: true, preserveState: true });
}

/** Round progress is only worth a bar when the round has more than one task. */
const rounds = computed(() => props.today.rounds.filter((r) => r.total > 1));

// Fresh data from the server lists tasks undone here as open again.
watch(() => props.today, () => queue.forgetRestored());

/** Tasks whose undo is still on the way to the server (the server lists them as skipped / moved). */
const restoringIds = computed(() => new Set(queue.restoring.value.map((r) => r.activity.id)));

/**
 * Open work, minus what was ticked on this phone and not yet reflected by the
 * server, plus what was undone here and hasn't reached the server yet (back
 * in its room, in walking order).
 */
const open = computed(() => {
    const groups = props.today.open.map((g) => ({ ...g, items: [...g.items] }));
    const listed = new Set(groups.flatMap((g) => g.items.map((a) => a.id)));
    for (const r of queue.restoring.value) {
        if (listed.has(r.activity.id)) continue;
        let group = groups.find((g) => g.room === r.room);
        if (!group) groups.push((group = { room: r.room, items: [] }));
        group.items.push(r.activity);
        group.items.sort((x, y) => (x.vessel?.name ?? '').localeCompare(y.vessel?.name ?? '', undefined, { numeric: true }));
        listed.add(r.activity.id);
    }

    return groups.map((g) => ({ ...g, items: g.items.filter((a) => queue.local[a.id] === undefined) })).filter((g) => g.items.length > 0);
});
/** Done / skipped today — minus what's being undone here. */
const completed = computed(() => props.today.completed.filter((a) => !restoringIds.value.has(a.id)));
const tickedHere = computed(() => {
    const seen = new Set<string>();

    return [...props.today.open.flatMap((g) => g.items), ...queue.restoring.value.map((r) => r.activity)].filter((a) => {
        if (seen.has(a.id) || queue.local[a.id] === undefined) return false;
        seen.add(a.id);

        return true;
    });
});
const openCount = computed(() => open.value.reduce((n, g) => n + g.items.length, 0));
const progress = computed(() => {
    const done = completed.value.length + tickedHere.value.length;
    const total = done + openCount.value;

    return { done, total, pct: total ? (done / total) * 100 : 0 };
});
const walk = (items: { vessel: { name: string } | null }[]) => [...new Set(items.map((a) => a.vessel?.name).filter(Boolean))].join(' → ');

/** "Save · next": put the cursor in the next card's first reading field. */
async function focusNext(id: string): Promise<void> {
    await nextTick();
    const cards = [...document.querySelectorAll<HTMLElement>('[data-activity]')];
    const from = cards.findIndex((c) => c.dataset.activity === id);
    const next = cards.slice(from + 1).find((c) => c.querySelector('input[data-reading]')) ?? cards.find((c) => c.dataset.activity !== id && c.querySelector('input[data-reading]'));
    next?.querySelector<HTMLInputElement>('input[data-reading]')?.focus();
    next?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}
</script>

<template>
    <Head :title="t('Cellar today')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Cellar today')" :description="subtitle" />
            <CellarTabs current="today" />

            <div class="mx-auto flex w-full max-w-lg flex-col gap-4 pb-24">
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{ t(':done of :total done', { done: progress.done, total: progress.total }) }}</span>
                        <span v-if="!queue.online.value" class="inline-flex items-center gap-1 text-tag-orange-foreground"><CloudOff class="size-3.5" /> {{ t('Offline — ticks are kept on this phone') }}</span>
                        <span v-else-if="queue.pending.value" class="inline-flex items-center gap-1 text-tag-orange-foreground"><RefreshCw class="size-3.5 animate-spin" /> {{ t(':n waiting to sync', { n: queue.pending.value }) }}</span>
                    </div>
                    <ProgressBar :value="progress.pct" />
                </div>
                <Tabs
                    v-if="showToggle"
                    :items="scopeTabs"
                    :current="mine ? 'mine' : 'all'"
                    full
                    @select="setScope"
                />

                <div v-if="rounds.length" class="flex flex-col gap-2">
                    <div v-for="r in rounds" :key="r.id" class="border border-border bg-card px-3 py-2">
                        <div class="mb-1.5 flex items-center justify-between gap-2 text-xs">
                            <span class="truncate font-medium text-foreground">{{ r.name }}</span>
                            <span class="shrink-0 tabular-nums text-muted-foreground">{{ r.done + r.skipped }}/{{ r.total }}</span>
                        </div>
                        <ProgressBar :value="r.total ? ((r.done + r.skipped) / r.total) * 100 : 0" />
                    </div>
                </div>

                <div v-if="errors.length" class="border border-destructive/40 bg-tag-red px-3 py-2 text-sm text-tag-red-foreground" role="alert">
                    <p v-for="(e, i) in errors" :key="i">{{ e }}</p>
                </div>

                <div v-if="openCount === 0" class="flex flex-col items-center gap-2 border border-dashed border-border px-6 py-10 text-center">
                    <Check class="size-8 text-success" />
                    <p class="font-semibold text-foreground">{{ t('All done!') }}</p>
                    <p class="text-sm text-muted-foreground">{{ t('No planned cellar work left for today.') }}</p>
                    <Link v-if="canManage" href="/cellar/plan" class="mt-1 text-xs text-foreground underline underline-offset-2">
                        {{ t('Plan work') }}
                    </Link>
                </div>

                <section v-for="group in open" :key="group.room" class="flex flex-col gap-2">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ t(group.room) }}<template v-if="walk(group.items)"> · {{ walk(group.items) }}</template>
                    </h3>
                    <TodayCard
                        v-for="a in group.items"
                        :key="a.id"
                        :activity="a"
                        :today="today.today"
                        :me-id="meId"
                        :can-manage="canManage"
                        :room="group.room"
                        @next="focusNext"
                    />
                </section>

                <section v-if="completed.length || tickedHere.length" class="flex flex-col gap-1">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ t('Completed today') }}</h3>
                    <div v-for="a in tickedHere" :key="`here-${a.id}`" class="flex items-center gap-2 border-b border-border py-1.5 text-xs">
                        <Check v-if="queue.local[a.id] === 'complete'" class="size-4 shrink-0 text-success" />
                        <X v-else class="size-4 shrink-0 text-muted-foreground" />
                        <span class="w-28 shrink-0 truncate font-medium text-foreground">{{ activityTitle(a) }}</span>
                        <span class="min-w-0 flex-1 truncate text-muted-foreground">{{ queue.local[a.id] === 'reschedule' ? t('moved') : describeActivity(a, t) }}</span>
                        <span v-if="queue.isQueued(a.id)" class="shrink-0 text-tag-orange-foreground">{{ t('waiting to sync') }}</span>
                    </div>
                    <div
                        v-for="a in completed"
                        :key="a.id"
                        class="flex items-center gap-2 border-b border-border py-1.5 text-xs last:border-0"
                    >
                        <Check v-if="a.status === 'DONE'" class="size-4 shrink-0 text-success" />
                        <X v-else class="size-4 shrink-0 text-muted-foreground" />
                        <span class="w-28 shrink-0 truncate font-medium text-foreground">{{ activityTitle(a) }}</span>
                        <span class="min-w-0 flex-1 truncate text-muted-foreground">{{ describeActivity(a, t) }}</span>
                        <span v-if="a.deviated" class="shrink-0 bg-tag-orange px-1.5 py-0.5 text-[10px] text-tag-orange-foreground">{{ t('adjusted') }}</span>
                        <span v-if="a.status === 'SKIPPED' && a.skip_reason" class="shrink-0 text-muted-foreground">
                            {{ t(SKIP_REASON_LABELS[a.skip_reason] ?? a.skip_reason) }}
                        </span>
                        <span v-if="a.executed_by" class="hidden shrink-0 text-muted-foreground sm:inline">{{ a.executed_by.name }}</span>
                    </div>
                </section>
            </div>
        </div>
</template>
