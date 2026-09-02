<script setup lang="ts">
import { cn } from '@/lib/cn';

const props = defineProps<{ class?: string }>();
</script>

<template>
    <h3 :class="cn('text-sm font-semibold', props.class)">
        <slot />
    </h3>
</template>
