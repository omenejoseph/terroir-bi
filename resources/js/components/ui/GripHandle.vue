<script setup lang="ts">
import { GripVertical } from 'lucide-vue-next';

/**
 * The reorder grip the design puts at the head of every inventory row and band
 * (Figma `389:1592`).
 *
 * Presentational for now: `sort_order` exists on the model but drag-to-reorder
 * is not wired, so this carries no interaction and is hidden from assistive
 * tech rather than announcing a control that does nothing. See
 * docs/design/README.md.
 */
</script>

<template>
    <GripVertical class="size-3.5 text-muted-foreground/40" :stroke-width="2" aria-hidden="true" />
</template>
