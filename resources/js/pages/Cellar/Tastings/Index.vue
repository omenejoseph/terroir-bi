<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import TastingReportPanel from '@/components/cellar/TastingReportPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { TastingReport } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { ComboboxOption } from '@/types/ui';

/** Cellar › Tastings — the panel tasting reports, newest first. */
defineProps<{ reports: TastingReport[]; lots: ComboboxOption[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const locale = computed(() => page.props.locale);
const panel = ref(false);
</script>

<template>
    <AppLayout :title="t('Tasting reports')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Tasting reports')">
                <template v-if="can('cellar.manage')" #actions>
                    <Button size="sm" @click="panel = true"><Plus class="size-3.5" /> {{ t('New report') }}</Button>
                </template>
            </PageHeader>
            <CellarTabs current="tastings" />

            <div class="flex flex-col gap-2">
                <Link v-for="r in reports" :key="r.id" :href="`/cellar/tastings/${r.id}`">
                    <Card class="flex items-center gap-4 p-4 hover:bg-accent/40">
                        <div class="w-14 shrink-0 text-center">
                            <p class="text-2xl font-semibold">{{ r.average_score ?? '—' }}</p>
                            <p class="text-[10px] text-muted-foreground">{{ t('avg /100') }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ r.title || t('Tasting') }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ new Date(r.date).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) }} ·
                                {{ t(':n wines', { n: r.wine_count }) }}<template v-if="r.wines_preview.length"> · {{ r.wines_preview.join(', ') }}</template>
                            </p>
                        </div>
                        <span v-if="r.author" class="hidden shrink-0 text-xs text-muted-foreground sm:inline">{{ r.author.name }}</span>
                    </Card>
                </Link>
                <p v-if="reports.length === 0" class="border border-dashed border-border p-10 text-center text-sm text-muted-foreground">{{ t('No tasting reports yet.') }}</p>
            </div>
        </div>
        <TastingReportPanel :open="panel" :report="null" :lots="lots" @close="panel = false" />
    </AppLayout>
</template>
