<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import TastingReportPanel from '@/components/cellar/TastingReportPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { TastingReport } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { ComboboxOption } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/** One tasting report: who tasted, when, the average, and each wine's score and notes (linked to its lot). */
const props = defineProps<{ report: TastingReport; lots: ComboboxOption[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const locale = computed(() => page.props.locale);
const panel = ref(false);

async function destroy(): Promise<void> {
    const ok = await confirmDialog({ title: t('Delete this report?'), description: t('Its wines’ tasting notes are removed from their lots too.'), confirmLabel: t('Delete'), tone: 'danger' });
    if (ok) router.delete(`/cellar/tastings/${props.report.id}`);
}
</script>

<template>
    <Head :title="report.title || t('Tasting')" />
        <div class="flex flex-col gap-5">
            <Link href="/cellar/tastings" class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> {{ t('Tasting reports') }}</Link>
            <PageHeader :title="report.title || t('Tasting')" :description="[new Date(report.date).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' }), report.author?.name].filter(Boolean).join(' · ')">
                <template v-if="can('cellar.manage')" #actions>
                    <Button size="sm" variant="outline" @click="panel = true"><Pencil class="size-3.5" /> {{ t('Edit') }}</Button>
                    <Button size="sm" variant="ghost" :aria-label="t('Delete')" @click="destroy"><Trash2 class="size-3.5" /></Button>
                </template>
            </PageHeader>
            <CellarTabs current="tastings" />

            <Card class="flex flex-wrap items-center gap-8 p-4">
                <div><p class="text-[10px] uppercase text-muted-foreground">{{ t('Wines') }}</p><p class="text-2xl font-semibold">{{ report.wine_count }}</p></div>
                <div><p class="text-[10px] uppercase text-muted-foreground">{{ t('Avg score /100') }}</p><p class="text-2xl font-semibold">{{ report.average_score ?? '—' }}</p></div>
                <p v-if="report.note" class="min-w-0 flex-1 text-sm">{{ report.note }}</p>
            </Card>

            <div class="flex flex-col gap-2">
                <Card v-for="w in report.wines ?? []" :key="w.note_id" class="flex gap-4 p-4 text-sm">
                    <div class="w-14 shrink-0 text-center"><p class="text-2xl font-semibold">{{ w.score ?? '—' }}</p><p class="text-[10px] text-muted-foreground">/100</p></div>
                    <div class="min-w-0 flex-1">
                        <Link v-if="w.wine_lot" :href="`/cellar/lots/${w.wine_lot.id}`" class="font-medium hover:underline">{{ w.wine_lot.name }} {{ w.wine_lot.vintage }}</Link>
                        <p v-if="w.nose" class="text-xs"><span class="text-muted-foreground">{{ t('Nose') }}:</span> {{ w.nose }}</p>
                        <p v-if="w.palate" class="text-xs"><span class="text-muted-foreground">{{ t('Palate') }}:</span> {{ w.palate }}</p>
                        <p v-if="w.note" class="text-xs text-muted-foreground">{{ w.note }}</p>
                    </div>
                </Card>
            </div>
        </div>
        <TastingReportPanel :open="panel" :report="report" :lots="lots" @close="panel = false" />
</template>
