<script setup lang="ts">
/**
 * Figma component `MetaStrip` — 8 instances across 4 sections. The middot-joined
 * identity line under a page title, e.g.
 * "LOTR32026 · Finished Product · 2025 · 2.595 bottles" on `449:1577`.
 *
 * Empty entries are dropped rather than leaving a dangling separator.
 */
defineProps<{ items: (string | null | undefined)[] }>();
</script>

<template>
    <p class="text-13 text-muted-foreground">
        <template v-for="(item, i) in items.filter(Boolean)" :key="i">
            <span v-if="i > 0" aria-hidden="true"> · </span>{{ item }}
        </template>
    </p>
</template>
