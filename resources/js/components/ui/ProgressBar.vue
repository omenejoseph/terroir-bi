<script setup lang="ts">
import { computed } from 'vue';

/** ProgressTrack / ProgressIndicator from the design's target cards. */
const props = withDefaults(defineProps<{ value: number; label?: string }>(), { value: 0 });

const pct = computed(() => Math.max(0, Math.min(100, props.value)));
</script>

<template>
    <div
        class="h-2 w-full overflow-hidden rounded-full bg-muted"
        role="progressbar"
        :aria-valuenow="pct"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="label"
    >
        <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${pct}%` }" />
    </div>
</template>
