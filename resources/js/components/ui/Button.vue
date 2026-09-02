<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

import { cn } from '@/lib/cn';

type Variant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
type Size = 'sm' | 'md' | 'lg' | 'icon';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        size?: Size;
        /** Renders an Inertia <Link> instead of a <button> when set. */
        href?: string;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        class?: string;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', disabled: false },
);

/*
  Every variant is expressed in design tokens (bg-primary, border-input …), never
  a literal colour, so re-theming happens entirely in resources/css/app.css.
*/
const VARIANTS: Record<Variant, string> = {
    primary: 'bg-primary text-primary-foreground hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    outline: 'border border-input bg-transparent hover:bg-accent hover:text-accent-foreground',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
};

/*
  Two button heights exist in the design and they are not interchangeable:
  page toolbars use 28px buttons with a 12px label (Figma 382:1592 "Bulk Import"
  is 103x28 with a 63px-wide 12px label; 389:1592 matches), while form footers
  use 36px with a 14px label (322:704 "Create item" is 99x36). Horizontal
  padding is 11-12px in both. `lg` has no counterpart in the design and is kept
  only for surfaces that have not been designed yet.
*/
const SIZES: Record<Size, string> = {
    sm: 'h-7 gap-1 px-3 text-xs',
    md: 'h-9 gap-2 px-3 text-sm',
    lg: 'h-11 gap-2 px-6 text-base',
    icon: 'size-8',
};

const classes = computed(() =>
    cn(
        'inline-flex items-center justify-center rounded-lg font-medium whitespace-nowrap transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        'disabled:pointer-events-none disabled:opacity-50',
        VARIANTS[props.variant],
        SIZES[props.size],
        props.class,
    ),
);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
