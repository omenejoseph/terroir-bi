<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

/**
 * The inventory "Level" column (Figma 389:1592).
 *
 * The design captions this "<on hand> of <max> max" — stock against a ceiling.
 * The schema has no maximum-stock field, only `min_stock`, so this shows the
 * inverse: stock against its MINIMUM, full once the item is at or above it and
 * red below. Reusing `min_stock` as if it were a maximum would invert the
 * meaning of the bar. A true max-stock field is listed in
 * docs/design/README.md as a gap.
 *
 * Both figures arrive as decimal strings; they are parsed only to size the bar,
 * never to render a number.
 */
const props = defineProps<{ value: string; min: string | null }>();

const parsed = computed(() => {
    const v = Number.parseFloat(props.value);
    const m = props.min === null ? Number.NaN : Number.parseFloat(props.min);

    return { value: Number.isFinite(v) ? v : null, min: Number.isFinite(m) && m > 0 ? m : null };
});

const pct = computed(() => {
    const { value, min } = parsed.value;

    if (value === null || min === null) return null;

    return Math.max(0, Math.min(100, (value / min) * 100));
});

const belowMin = computed(() => {
    const { value, min } = parsed.value;

    return value !== null && min !== null && value < min;
});
</script>

<template>
    <div class="min-w-32">
        <div v-if="pct !== null" class="h-1.5 w-full overflow-hidden bg-muted">
            <div
                :class="cn('h-full', belowMin ? 'bg-destructive' : 'bg-foreground/70')"
                :style="{ width: `${pct}%` }"
            />
        </div>
        <p class="mt-1 text-2xs" :class="belowMin ? 'text-destructive' : 'text-muted-foreground'">
            <slot />
        </p>
    </div>
</template>
