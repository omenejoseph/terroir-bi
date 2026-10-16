<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Pagination from '@/components/ui/Pagination.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { qty } from '@/lib/cellar';
import type { CellarBookRow } from '@/types/cellar';
import type { Paginated } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar › Cellar book — every cellar event for every lot in a date range
 * (intake, vessel fills, additions, transfers, processes incl. losses and
 * gains, analyses, bottlings), as the register an inspector asks for, with
 * a CSV download. The prototype had an export only for harvest-linked lots.
 */
const props = defineProps<{ rows: Paginated<CellarBookRow>; filters: { from: string; to: string } }>();

const { t } = useTranslations();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const csv = computed(() => `/cellar/book.csv?from=${props.filters.from}&to=${props.filters.to}`);

function apply(extra: Record<string, unknown> = {}): void {
    router.get('/cellar/book', { from: from.value, to: to.value, per_page: props.rows.meta.per_page, ...extra }, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <Head :title="t('Cellar book')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Cellar book')" :description="t('Every cellar event in the period, for every lot.')">
                <template #actions><Button size="sm" variant="outline" :href="csv" download><Download class="size-3.5" /> CSV</Button></template>
            </PageHeader>
            <CellarTabs current="book" />

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <label class="flex items-center gap-2">{{ t('From') }} <Input v-model="from" type="date" class="w-40" /></label>
                <label class="flex items-center gap-2">{{ t('To') }} <Input v-model="to" type="date" class="w-40" /></label>
                <Button size="sm" @click="apply()">{{ t('Show') }}</Button>
                <span class="text-xs text-muted-foreground">{{ t(':n events', { n: rows.meta.total }) }}</span>
            </div>

            <Card>
                <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Date') }}</th>
                            <th class="px-2 py-2 text-left font-medium">{{ t('Lot') }}</th>
                            <th class="px-2 py-2 text-left font-medium">{{ t('Event') }}</th>
                            <th class="px-2 py-2 text-left font-medium">{{ t('Vessel') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Quantity') }}</th>
                            <th class="px-2 py-2 text-left font-medium">{{ t('Detail') }}</th>
                            <th class="hidden px-2 py-2 text-left font-medium md:table-cell">{{ t('By') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in rows.data" :key="i" class="border-t border-border">
                            <td class="whitespace-nowrap px-3 py-1.5">{{ r.date }}</td>
                            <td class="px-2 py-1.5"><span class="font-medium">{{ r.lot_number }}</span> <span class="text-muted-foreground">{{ r.lot_name }} {{ r.vintage }}</span></td>
                            <td class="px-2 py-1.5">{{ t(r.event) }}</td>
                            <td class="px-2 py-1.5">{{ r.vessel ?? '—' }}</td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right tabular-nums">{{ r.quantity === null ? '—' : `${qty(r.quantity)} ${r.unit ?? ''}` }}</td>
                            <td class="px-2 py-1.5 text-muted-foreground">{{ r.detail ?? '' }}</td>
                            <td class="hidden px-2 py-1.5 md:table-cell">{{ r.by ?? '' }}</td>
                        </tr>
                        <tr v-if="rows.data.length === 0"><td colspan="7" class="px-3 py-10 text-center text-muted-foreground">{{ t('No cellar events in this period.') }}</td></tr>
                    </tbody>
                </table>
                </div>
                <div v-if="rows.meta.total > 10" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-3 py-3">
                    <p class="text-xs text-muted-foreground">{{ t('Showing :from–:to of :total', { from: (rows.meta.current_page - 1) * rows.meta.per_page + 1, to: Math.min(rows.meta.current_page * rows.meta.per_page, rows.meta.total), total: rows.meta.total }) }}</p>
                    <Pagination :meta="rows.meta" @update:page="(p) => apply({ page: p })" @update:per-page="(n) => apply({ per_page: n, page: 1 })" />
                </div>
            </Card>
        </div>
</template>
