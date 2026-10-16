<script setup lang="ts">
import { computed, ref } from 'vue';
import { Check } from 'lucide-vue-next';

import Input from '@/components/ui/Input.vue';
import { type QueuedAction, useActivityQueue } from '@/composables/useActivityQueue';
import { useTranslations } from '@/composables/useTranslations';
import { ACTIVITY_ICONS, METRIC_LABELS, activityTitle, addDays, describeActivity, lotLabel, parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { AnalysisParam, CellarActivity } from '@/types/cellar';

/**
 * One planned task on Cellar › Today, built for a phone held at the tank:
 * the vessel is the headline, the one-tap ✓ is 56px, and every number field
 * opens the decimal keypad. Readings are typed right on the card ("Save ·
 * next" moves to the next card's first field); swiping the card left opens
 * Skip / postpone. Ticks go through the offline queue: the card is done at
 * once and the server hears about it as soon as it can.
 *
 * Adjust is offered where a planned amount can differ in practice (addition,
 * transfer, topping). The prototype also offered it on vineyard work, where it
 * asked for litres — that quirk is not carried over.
 */
const props = defineProps<{
    activity: CellarActivity;
    today: string;
    meId: string | null;
    canManage: boolean;
}>();

const emit = defineEmits<{ next: [id: string] }>();

const { t } = useTranslations();
const queue = useActivityQueue();
const error = computed(() => queue.errors[props.activity.id] ?? null);

type Panel = 'adjust' | 'readings' | 'skip' | null;
// Reading tasks open with their fields — that's the whole job at the tank.
const panel = ref<Panel>(props.activity.type === 'ANALYSIS' && props.canManage ? 'readings' : null);
const amount = ref('');
const readings = ref<Partial<Record<AnalysisParam, string>>>({});
const otherDate = ref('');

const a = computed(() => props.activity);
const icon = computed(() => ACTIVITY_ICONS[a.value.type]);
const isAnalysis = computed(() => a.value.type === 'ANALYSIS');
const canAdjust = computed(() => ['ADDITION', 'TRANSFER', 'TOPPING'].includes(a.value.type));
const unit = computed(() => (a.value.type === 'ADDITION' ? (a.value.payload.unit ?? 'g') : 'L'));
const metrics = computed<AnalysisParam[]>(() => a.value.payload.metrics ?? ['free_so2']);
const assignedElsewhere = computed(() => a.value.assigned_to !== null && a.value.assigned_to.id !== props.meId);

const amountValue = computed(() => parseDecimal(amount.value));
const parsedReadings = computed(() => {
    const out: Partial<Record<AnalysisParam, number>> = {};
    for (const m of metrics.value) {
        const v = parseDecimal(readings.value[m]);
        if (v !== null) out[m] = v;
    }

    return out;
});

function toggle(next: Exclude<Panel, null>): void {
    if (panel.value === next) {
        panel.value = null;

        return;
    }
    panel.value = next;
    if (next === 'adjust') {
        amount.value = String(a.value.type === 'ADDITION' ? (a.value.payload.quantity ?? '') : (a.value.payload.liters ?? ''));
    }
}

function post(action: QueuedAction, data: Record<string, unknown> = {}): void {
    void queue.submit(a.value.id, action, data);
}

function done(): void {
    post('complete');
}

function saveAdjusted(): void {
    if (amountValue.value === null || amountValue.value <= 0) return;
    post('complete', a.value.type === 'ADDITION' ? { quantity: amountValue.value } : { liters: amountValue.value });
}

function saveReadings(): void {
    if (Object.keys(parsedReadings.value).length === 0) return;
    post('complete', { values: parsedReadings.value });
    emit('next', a.value.id);
}

// ── Swipe left → Skip / postpone (the buttons stay for everyone else) ──────
let startX: number | null = null;
const offset = ref(0);
function onDown(e: PointerEvent): void {
    if (!props.canManage || e.pointerType === 'mouse' || (e.target as HTMLElement).closest('input, button, label')) return;
    startX = e.clientX;
}
function onMove(e: PointerEvent): void {
    if (startX === null) return;
    offset.value = Math.min(0, Math.max(-120, e.clientX - startX));
}
function onUp(): void {
    if (startX !== null && offset.value <= -80) panel.value = 'skip';
    startX = null;
    offset.value = 0;
}

function moveTo(date: string, reason: string | null = null): void {
    if (date === '') return;
    post('reschedule', { planned_for: date, reason });
}

function skip(reason: 'not_needed' | 'other'): void {
    post('skip', { reason });
}
</script>

<template>
    <div
        :class="
            cn(
                'touch-pan-y border bg-card p-4 transition-transform',
                a.overdue ? 'border-tag-orange-foreground/50' : 'border-border',
                error !== null && 'border-destructive',
            )
        "
        :style="offset ? { transform: `translateX(${offset}px)` } : undefined"
        :data-activity="a.id"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
    >
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="text-2xl font-semibold leading-tight text-foreground">{{ activityTitle(a) }}</span>
                    <span
                        v-if="a.vessel && a.wine_lot"
                        class="bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                    >
                        {{ lotLabel(a.wine_lot.name, a.wine_lot.vintage) }}
                    </span>
                    <span v-if="a.overdue" class="bg-tag-orange px-1.5 py-0.5 text-[11px] font-medium text-tag-orange-foreground">
                        {{ t('overdue') }}
                    </span>
                    <span v-if="assignedElsewhere" class="bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                        {{ a.assigned_to?.name.split(' ')[0] }}
                    </span>
                </div>
                <div class="mt-1.5 flex items-center gap-1.5 text-sm text-foreground">
                    <component :is="icon" class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.75" />
                    <span class="min-w-0">{{ describeActivity(a, t) }}</span>
                </div>
                <p v-if="a.note" class="mt-1 truncate text-xs text-muted-foreground">{{ a.note }}</p>
                <p v-if="error !== null" class="mt-1 text-xs text-destructive" role="alert">{{ error || t('Could not save.') }}</p>
            </div>

            <template v-if="canManage">
                <button
                    v-if="!isAnalysis"
                    type="button"
                    class="flex size-14 shrink-0 items-center justify-center bg-success text-success-foreground transition-transform active:scale-95"
                    :aria-label="t('Done as planned')"
                    @click="done"
                >
                    <Check class="size-7" :stroke-width="2.5" />
                </button>
            </template>
        </div>

        <div v-if="canManage" class="mt-3 flex gap-2">
            <button
                v-if="canAdjust"
                type="button"
                :class="cn('h-9 flex-1 text-xs font-medium transition-colors', panel === 'adjust' ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground hover:bg-accent')"
               
                @click="toggle('adjust')"
            >
                {{ t('Adjust') }}
            </button>
            <button
                type="button"
                :class="cn('h-9 flex-1 text-xs font-medium transition-colors', panel === 'skip' ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground hover:bg-accent')"
               
                @click="toggle('skip')"
            >
                {{ t('Skip / postpone') }}
            </button>
        </div>

        <!-- Adjust: the amount actually used -->
        <div v-if="panel === 'adjust'" class="mt-3 flex items-center gap-2">
            <Input
                v-model="amount"
                inputmode="decimal"
                class="h-12 flex-1 text-center text-lg"
                :aria-label="t('Actual amount')"
            />
            <span class="w-8 text-sm text-muted-foreground">{{ unit }}</span>
            <button
                type="button"
                class="h-12 bg-success px-5 text-sm font-semibold text-success-foreground disabled:opacity-50"
                :disabled="amountValue === null || amountValue <= 0"
                @click="saveAdjusted"
            >
                {{ t('Done') }}
            </button>
        </div>

        <!-- Readings -->
        <div v-if="panel === 'readings'" class="mt-3 flex flex-col gap-2">
            <label v-for="m in metrics" :key="m" class="flex items-center gap-3">
                <span class="w-28 shrink-0 text-sm text-muted-foreground">{{ t(METRIC_LABELS[m] ?? m) }}</span>
                <Input v-model="readings[m]" inputmode="decimal" data-reading class="h-12 flex-1 text-center text-lg" @keydown.enter.prevent="saveReadings" />
            </label>
            <button
                type="button"
                class="h-12 bg-success text-sm font-semibold text-success-foreground disabled:opacity-50"
                :disabled="Object.keys(parsedReadings).length === 0"
                @click="saveReadings"
            >
                {{ t('Save · next') }}
            </button>
        </div>

        <!-- Skip / postpone -->
        <div v-if="panel === 'skip'" class="mt-3 flex flex-col gap-3">
            <div>
                <p class="mb-1.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{{ t('Move to') }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" class="h-10 bg-muted text-xs font-medium hover:bg-accent" @click="moveTo(addDays(today, 1), t('No time'))">
                        {{ t('Tomorrow') }}
                    </button>
                    <button type="button" class="h-10 bg-muted text-xs font-medium hover:bg-accent" @click="moveTo(addDays(today, 3), t('No time'))">
                        {{ t('+3 days') }}
                    </button>
                    <input
                        v-model="otherDate"
                        type="date"
                        :min="addDays(today, 1)"
                        class="h-10 border border-input bg-background px-2 text-xs"
                        :aria-label="t('Pick a date')"
                        @change="moveTo(otherDate)"
                    />
                </div>
            </div>
            <div>
                <p class="mb-1.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{{ t('Skip') }}</p>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="h-10 bg-muted text-xs font-medium hover:bg-accent" @click="skip('not_needed')">
                        {{ t('Not needed') }}
                    </button>
                    <button type="button" class="h-10 bg-muted text-xs font-medium hover:bg-accent" @click="skip('other')">
                        {{ t('Other') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
