<script setup lang="ts">
import { cn } from '@/lib/cn';

const props = defineProps<{ class?: string }>();
</script>

<template>
    <h3 :class="cn('text-lg leading-none font-semibold tracking-tight', props.class)">
        <slot />
    </h3>
</template>
