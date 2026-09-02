<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

type Variant = 'neutral' | 'success' | 'destructive' | 'outline';

const props = withDefaults(defineProps<{ variant?: Variant; class?: string }>(), { variant: 'neutral' });

const VARIANTS: Record<Variant, string> = {
    neutral: 'bg-secondary text-secondary-foreground',
    success: 'bg-success text-success-foreground',
    destructive: 'bg-destructive text-destructive-foreground',
    outline: 'border border-input text-foreground',
};

const classes = computed(() =>
    cn(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
        VARIANTS[props.variant],
        props.class,
    ),
);
</script>

<template>
    <span :class="classes"><slot /></span>
</template>
