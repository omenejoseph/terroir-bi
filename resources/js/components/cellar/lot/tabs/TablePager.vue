<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatNumber } from '@/lib/money';
import type { SharedProps } from '@/types';

/** The footer every lot table ends in: "Showing 1–25 of 166" and the shared pager. */
const props = defineProps<{ meta: { current_page: number; last_page: number; per_page: number; total: number } }>();
const emit = defineEmits<{ page: [page: number]; perPage: [perPage: number] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const showing = computed(() => {
    const { current_page: current, per_page: per, total } = props.meta;
    const first = total === 0 ? 0 : (current - 1) * per + 1;

    return t('Showing :from–:to of :total', {
        from: first,
        to: Math.min(current * per, total),
        total: formatNumber(total, page.props.locale),
    });
});
</script>

<template>
    <div v-if="meta.total > 10" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-3 py-3">
        <p class="text-xs text-muted-foreground">{{ showing }}</p>
        <Pagination :meta="meta" @update:page="emit('page', $event)" @update:per-page="emit('perPage', $event)" />
    </div>
</template>
