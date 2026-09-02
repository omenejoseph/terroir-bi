<script setup lang="ts">
import { Grape } from 'lucide-vue-next';

import { cn } from '@/lib/cn';

/**
 * The product mark: a dark tile carrying the grape glyph in the tile's
 * foreground colour (Figma `547:1570` expanded, `230:2401` collapsed — the same
 * component in both nav states).
 *
 * Geometry is fixed by the design at a 32px tile with an 8px radius around a
 * 16px glyph, and the ratio holds at other sizes, so `size` scales the tile and
 * the glyph together rather than letting a caller set them apart.
 *
 * The tile is one of exactly two rounded shapes in the whole design — the
 * avatar is the other — which is why it names its radius instead of taking the
 * global one; see the radius note in app.css.
 */
const props = withDefaults(defineProps<{ size?: number; class?: string }>(), { size: 32 });
</script>

<template>
    <span
        :class="cn('inline-flex shrink-0 items-center justify-center bg-primary text-primary-foreground', props.class)"
        :style="{
            width: `${props.size}px`,
            height: `${props.size}px`,
            borderRadius: `${(props.size / 32) * 8}px`,
        }"
    >
        <Grape
            :size="props.size / 2"
            :stroke-width="1.5"
            aria-hidden="true"
        />
    </span>
</template>
