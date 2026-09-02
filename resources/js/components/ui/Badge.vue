<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

type Variant = 'neutral' | 'solid' | 'success' | 'destructive' | 'outline' | 'warning';

const props = withDefaults(defineProps<{ variant?: Variant; class?: string }>(), { variant: 'neutral' });

const VARIANTS: Record<Variant, string> = {
    neutral: 'bg-secondary text-secondary-foreground',
    // Figma 376:1592 gives the order's own status a solid dark chip, so it
    // reads as the subject of the strip rather than one attribute among many.
    solid: 'bg-primary text-primary-foreground',
    success: 'bg-success text-success-foreground',
    destructive: 'bg-destructive text-destructive-foreground',
    outline: 'border border-input text-foreground',
    // Figma 378:1592 marks data-quality problems with a red outline, not a fill.
    warning: 'border border-destructive text-destructive',
};

const classes = computed(() =>
    cn(
        'inline-flex items-center px-2.5 py-0.5 text-xs font-medium',
        VARIANTS[props.variant],
        props.class,
    ),
);
</script>

<template>
    <span :class="classes"><slot /></span>
</template>
