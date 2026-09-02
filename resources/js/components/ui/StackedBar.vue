<script setup lang="ts">
import { computed } from 'vue';

/**
 * A single proportional bar with a swatch legend beneath it — the "What it
 * earned" split on Figma `386:1673` (gross profit against cost of goods).
 *
 * Segments size themselves against their own total, so the bar always fills.
 */
const props = defineProps<{ segments: { label: string; value: number; caption?: string }[] }>();

const total = computed(() => props.segments.reduce((sum, s) => sum + Math.max(0, s.value), 0));

/** Fixed tones: the first segment is the emphasised one, the rest recede. */
const TONES = ['bg-foreground', 'bg-muted-foreground/35', 'bg-muted-foreground/20'];
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-muted" role="img">
            <div
                v-for="(segment, i) in segments"
                :key="segment.label"
                :class="TONES[i] ?? TONES[TONES.length - 1]"
                :style="{ width: `${total > 0 ? (Math.max(0, segment.value) / total) * 100 : 0}%` }"
            />
        </div>

        <ul class="flex flex-col gap-2">
            <li
                v-for="(segment, i) in segments"
                :key="segment.label"
                class="flex items-baseline justify-between gap-3 text-sm"
            >
                <span class="inline-flex items-center gap-2">
                    <span
                        class="size-2.5 shrink-0 rounded-[2px]"
                        :class="TONES[i] ?? TONES[TONES.length - 1]"
                        aria-hidden="true"
                    />
                    {{ segment.label }}
                </span>
                <span class="shrink-0 tabular-nums">
                    {{ segment.caption }}
                    <span v-if="total > 0" class="text-muted-foreground">
                        · {{ ((Math.max(0, segment.value) / total) * 100).toFixed(1) }} %
                    </span>
                </span>
            </li>
        </ul>
    </div>
</template>
