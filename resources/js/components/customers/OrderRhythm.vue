<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import { useTranslations } from '@/composables/useTranslations';
import type { CustomerRhythm } from '@/types/customers';
import type { SharedProps } from '@/types';

/**
 * "Order rhythm" (Figma 231:9336): every order in the window plotted as a tick
 * on a timeline, with the silence since the last one drawn as a red band
 * running to today when it has outrun the customer's typical gap.
 *
 * The strip's job is to make a rhythm — and a break in it — legible without
 * reading a number. The legend under it carries the numbers for when it isn't.
 */
const props = defineProps<{ rhythm: CustomerRhythm }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

const monthTicks = computed(() => {
    const from = new Date(props.rhythm.from);
    const to = new Date(props.rhythm.to);
    const span = Math.max(1, to.getTime() - from.getTime());

    const ticks: { label: string; position: number }[] = [];
    const cursor = new Date(from.getFullYear(), from.getMonth(), 1);

    // Start at the first whole month inside the window.
    if (cursor < from) cursor.setMonth(cursor.getMonth() + 1);

    while (cursor <= to) {
        ticks.push({
            label: cursor.toLocaleDateString(locale.value, { month: 'short' }),
            position: (cursor.getTime() - from.getTime()) / span,
        });
        cursor.setMonth(cursor.getMonth() + 1);
    }

    return ticks;
});

/** Where the silence starts: the last order, or the window's start if none. */
const gapStart = computed(() => {
    const last = props.rhythm.orders[props.rhythm.orders.length - 1];

    return last?.position ?? 0;
});

const label = computed(() => {
    const { from, to } = props.rhythm;
    const opts = { month: 'short', year: 'numeric' } as const;

    return `${new Date(from).toLocaleDateString(locale.value, opts)} – ${new Date(to).toLocaleDateString(locale.value, opts)}`;
});
</script>

<template>
    <div class="border border-border bg-card">
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-border px-6 py-4">
            <h3 class="text-sm font-semibold">{{ t('Order rhythm') }}</h3>
            <span class="text-xs text-muted-foreground">{{ label }}</span>
        </div>

        <div class="px-6 py-6">
            <!--
              Four lanes so nothing sits on anything else: labels (Expected / Today) on top, the
              timeline with its order ticks, the "days without an order" note, then the month scale.
            -->
            <div class="relative h-24">
                <!-- The overdue band, drawn only when the silence has outrun the customer's own cadence. -->
                <span
                    v-if="rhythm.overdue"
                    class="absolute top-8 h-2 bg-destructive/15"
                    :style="{ left: `${gapStart * 100}%`, right: '0' }"
                    aria-hidden="true"
                />

                <!-- Baseline the ticks sit on -->
                <span class="absolute inset-x-0 top-[2.25rem] h-px bg-border" aria-hidden="true" />

                <!-- Expected-next marker -->
                <template v-if="rhythm.expected_next_position !== null">
                    <span
                        class="absolute top-4 h-6 w-px bg-border"
                        :style="{ left: `${rhythm.expected_next_position * 100}%` }"
                        aria-hidden="true"
                    />
                    <span
                        class="absolute top-0 -translate-x-1/2 text-2xs whitespace-nowrap text-muted-foreground"
                        :style="{ left: `${Math.min(Math.max(rhythm.expected_next_position, 0.08), 0.82) * 100}%` }"
                    >
                        {{
                            t('Expected :date', {
                                date: new Date(rhythm.expected_next_date!).toLocaleDateString(locale, {
                                    day: 'numeric',
                                    month: 'short',
                                }),
                            })
                        }}
                    </span>
                </template>

                <!-- Today -->
                <span class="absolute top-4 right-0 h-6 w-0.5 bg-destructive" aria-hidden="true" />
                <span class="absolute top-0 right-0 text-2xs text-destructive">{{ t('Today') }}</span>

                <!-- One tick per order -->
                <span
                    v-for="(order, i) in rhythm.orders"
                    :key="`${order.date}-${i}`"
                    class="absolute top-8 size-2 -translate-x-1/2 bg-foreground"
                    :style="{ left: `${order.position * 100}%` }"
                    :title="new Date(order.date).toLocaleDateString(locale)"
                />

                <span
                    v-if="rhythm.overdue && rhythm.days_since_last !== null"
                    class="absolute top-[3.25rem] right-0 text-right text-2xs whitespace-nowrap text-destructive"
                >
                    {{ t(':days days without an order', { days: rhythm.days_since_last }) }}
                </span>

                <!-- Month scale -->
                <span
                    v-for="tick in monthTicks"
                    :key="tick.label + tick.position"
                    class="absolute bottom-0 -translate-x-1/2 text-2xs text-muted-foreground"
                    :style="{ left: `${tick.position * 100}%` }"
                >
                    {{ tick.label }}
                </span>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                <span class="inline-flex items-center gap-2">
                    <span class="size-2 bg-foreground" aria-hidden="true" />
                    {{ t(':count orders in this window', { count: rhythm.orders.length }) }}
                </span>
                <span v-if="rhythm.median_gap_days !== null" class="inline-flex items-center gap-2">
                    <span class="size-2 bg-muted-foreground/50" aria-hidden="true" />
                    {{ t('average gap :days days', { days: rhythm.median_gap_days }) }}
                </span>
                <span v-if="rhythm.days_since_last !== null" class="inline-flex items-center gap-2">
                    <span
                        class="size-2"
                        :class="rhythm.overdue ? 'bg-destructive' : 'bg-muted-foreground/50'"
                        aria-hidden="true"
                    />
                    {{ t('current gap :days days', { days: rhythm.days_since_last }) }}
                </span>
            </div>
        </div>
    </div>
</template>
