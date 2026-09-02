<script setup lang="ts">
import { computed } from 'vue';

/**
 * The stock range bar on Product Detail (Figma `449:1577`): a full-width track
 * with a tick at the minimum, captioned "minimum N" on the left and the
 * headroom on the right.
 *
 * Unlike the list's `LevelBar`, this one has a real ceiling to draw against —
 * the higher of current stock and twice the minimum — so the tick sits
 * somewhere meaningful rather than pinned to an edge.
 */
const props = defineProps<{ stock: number; min: number; unit: string }>();

const ceiling = computed(() => Math.max(props.stock, props.min * 2, 1));
const minPct = computed(() => Math.min(100, (props.min / ceiling.value) * 100));
const belowMin = computed(() => props.min > 0 && props.stock < props.min);
const headroom = computed(() => props.stock - props.min);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="relative h-2 w-full rounded-full bg-muted">
            <div
                class="h-full rounded-full"
                :class="belowMin ? 'bg-destructive' : 'bg-foreground'"
                :style="{ width: `${Math.min(100, (stock / ceiling) * 100)}%` }"
            />
            <span
                v-if="min > 0"
                class="absolute top-1/2 h-3.5 w-0.5 -translate-y-1/2 rounded-full bg-foreground/70"
                :style="{ left: `${minPct}%` }"
                aria-hidden="true"
            />
        </div>
        <div class="flex items-baseline justify-between gap-4 text-2xs text-muted-foreground">
            <span>{{ min > 0 ? `minimum ${min} ${unit}` : 'no minimum set' }}</span>
            <span v-if="min > 0" :class="belowMin && 'text-destructive'">
                {{ Math.abs(headroom) }} {{ unit }} {{ belowMin ? 'below minimum' : 'above minimum' }}
            </span>
        </div>
    </div>
</template>
