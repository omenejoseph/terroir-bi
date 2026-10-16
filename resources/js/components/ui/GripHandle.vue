<script setup lang="ts">
import { GripVertical } from 'lucide-vue-next';

/**
 * The reorder grip the design puts at the head of every inventory row and band
 * (Figma `389:1592`).
 *
 * Decorative by default. Give it `draggable` (and listen for `dragstart` /
 * `dragend`, which fall through to the root) and it becomes the handle for an
 * HTML5 drag — the Inventory list uses it to arrange items, subcategories and
 * groups.
 */
defineProps<{ draggable?: boolean; label?: string }>();
</script>

<template>
    <span
        class="inline-flex"
        :class="draggable ? 'cursor-grab text-muted-foreground active:cursor-grabbing' : undefined"
        :draggable="draggable"
        :title="draggable ? label : undefined"
        :aria-hidden="draggable ? undefined : true"
        @click.stop
    >
        <GripVertical class="size-3.5" :class="draggable ? 'opacity-70 hover:opacity-100' : 'text-muted-foreground/40'" :stroke-width="2" aria-hidden="true" />
    </span>
</template>
