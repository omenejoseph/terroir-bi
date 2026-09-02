<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { cn } from '@/lib/cn';
import type { Tab } from '@/types/ui';

/**
 * The sub-navigation strip a module shows above its content — e.g. Inventory's
 * Inventory / Analytics / Inventory Spend / Inventory Check (Figma 389:1592).
 *
 * A tab with `href: null` is a designed destination that is not built yet; it
 * renders disabled rather than as a dead link, matching how the sidebar treats
 * unported modules.
 */
defineProps<{ tabs: Tab[]; current: string }>();
</script>

<template>
    <nav class="flex flex-wrap items-center gap-1 border-b border-border" aria-label="Section">
        <template v-for="tab in tabs" :key="tab.label">
            <Link
                v-if="tab.href"
                :href="tab.href"
                :class="
                    cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                        tab.label === current
                            ? 'border-foreground font-medium text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )
                "
            >
                {{ tab.label }}
            </Link>
            <span
                v-else
                class="-mb-px cursor-not-allowed border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground/60"
                aria-disabled="true"
            >
                {{ tab.label }}
            </span>
        </template>
    </nav>
</template>
