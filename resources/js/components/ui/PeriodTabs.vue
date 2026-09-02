<script setup lang="ts">
import { router } from '@inertiajs/vue3';

import { cn } from '@/lib/cn';

/**
 * The dashboard period selector from the design (Figma 208:5577).
 *
 * Labels are the design's; values are the tokens DashboardSummary::resolveWindow
 * actually accepts, so a tab press is a real server-side window change rather
 * than a client-side filter.
 */
const props = defineProps<{ current: string | null }>();

const PERIODS = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'week', label: 'This Week' },
    { value: 'mtd', label: 'This Month' },
    { value: 'qtd', label: 'This Quarter' },
    { value: 'ytd', label: 'Year to Date' },
] as const;

function select(value: string): void {
    router.get('/dashboard', { period: value }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <div class="inline-flex flex-wrap items-center gap-1 rounded-lg bg-muted p-1" role="tablist">
        <button
            v-for="period in PERIODS"
            :key="period.value"
            type="button"
            role="tab"
            :aria-selected="props.current === period.value"
            :class="
                cn(
                    'rounded-md px-3 py-1.5 text-13 transition-colors',
                    props.current === period.value
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="select(period.value)"
        >
            {{ period.label }}
        </button>
    </div>
</template>
