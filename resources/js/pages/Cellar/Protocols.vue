<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Copy, Pencil, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import ProtocolFormPanel from '@/components/cellar/ProtocolFormPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { WINE_TYPE_LABELS, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { FermentationAlert, FermentationTemplate, ProductOption, WineType } from '@/types/cellar';
import type { MenuItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar › Protocols — fermentation protocol templates (assigned to lots on
 * the lot page) and the fermentation monitor: lots whose fermentation looks
 * stuck, too warm or overdue.
 */
defineProps<{ templates: FermentationTemplate[]; alerts: FermentationAlert[]; products: ProductOption[] }>();

const { t } = useTranslations();
const { can } = useAuth();

const panel = ref(false);
const editing = ref<FermentationTemplate | null>(null);
const duplicating = ref(false);

const menu = computed<MenuItem[]>(() => [
    { key: 'edit', label: t('Edit'), icon: Pencil },
    { key: 'duplicate', label: t('Duplicate'), icon: Copy },
    ...(can('cellar.delete') ? [{ key: 'delete', label: t('Delete'), icon: Trash2, destructive: true }] : []),
]);

async function onMenu(tp: FermentationTemplate, key: string): Promise<void> {
    if (key === 'edit' || key === 'duplicate') {
        editing.value = tp;
        duplicating.value = key === 'duplicate';
        panel.value = true;
    }
    if (key === 'delete' && (await confirmDialog({ title: t('Delete :name?', { name: tp.name }), description: t('Lots using it keep their records but lose the protocol.'), confirmLabel: t('Delete'), tone: 'danger' }))) {
        router.delete(`/cellar/protocols/${tp.id}`, { preserveScroll: true });
    }
}

function stageDays(s: FermentationTemplate['stages'][number]): string {
    return t('day :a–:b', { a: s.day_start ?? s.dayStart ?? 0, b: s.day_end ?? s.dayEnd ?? 0 });
}
const BASIS: Record<string, string> = { per_hL: '/hL', per_L: '/L', per_kg: '/kg', per_100kg: '/100 kg' };
const SEVERITY: Record<string, string> = { critical: 'bg-tag-red text-tag-red-foreground', warning: 'bg-tag-orange text-tag-orange-foreground', info: 'bg-tag-blue text-tag-blue-foreground' };
</script>

<template>
    <Head :title="t('Fermentation protocols')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Fermentation protocols')" :description="t('Assign a protocol on a lot’s page; its stages become tasks and its doses become planned additions.')">
                <template v-if="can('cellar.manage')" #actions>
                    <Button size="sm" @click="((editing = null), (duplicating = false), (panel = true))"><Plus class="size-3.5" /> {{ t('Protocol') }}</Button>
                </template>
            </PageHeader>
            <CellarTabs current="protocols" />

            <Card class="p-4">
                <SectionHeader :title="t('Fermentation monitor')" />
                <div v-for="(a, i) in alerts" :key="i" class="mt-2 flex items-start gap-3 border-b border-border pb-2 text-xs last:border-0">
                    <span :class="cn('shrink-0 px-1.5 py-0.5 text-[10px] font-medium uppercase', SEVERITY[a.severity] ?? SEVERITY.info)"><AlertTriangle class="inline size-3" /> {{ a.severity }}</span>
                    <div class="min-w-0 flex-1">
                        <Link :href="`/cellar/lots/${a.lot_id}`" class="font-medium hover:underline">{{ a.lot_name }}</Link> — {{ a.message }}
                        <p class="text-muted-foreground">{{ a.suggestion }}</p>
                    </div>
                </div>
                <p v-if="alerts.length === 0" class="mt-2 text-xs text-muted-foreground">{{ t('No fermentation alerts.') }}</p>
            </Card>

            <div class="grid gap-3 lg:grid-cols-2">
                <Card v-for="tp in templates" :key="tp.id" :class="cn('flex flex-col gap-2 p-4 text-xs', !tp.is_active && 'opacity-60')">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold">{{ tp.name }}</p>
                            <p class="text-muted-foreground">
                                {{ [tp.wine_type ? t(WINE_TYPE_LABELS[tp.wine_type as WineType] ?? tp.wine_type) : null, tp.yeast_strain, tp.target_temp_min || tp.target_temp_max ? `${qty(tp.target_temp_min)}–${qty(tp.target_temp_max)} °C` : null, tp.estimated_duration ? t(':n days', { n: tp.estimated_duration }) : null, tp.mlf ? t('MLF') : null].filter(Boolean).join(' · ') }}
                            </p>
                        </div>
                        <DropdownMenu v-if="can('cellar.manage')" :items="menu" @select="onMenu(tp, $event)" />
                    </div>
                    <p v-if="tp.description">{{ tp.description }}</p>
                    <!-- stage timeline -->
                    <div v-if="tp.stages.length" class="flex flex-col gap-1">
                        <div v-for="(s, i) in tp.stages" :key="i" class="flex gap-2">
                            <span class="w-20 shrink-0 text-muted-foreground">{{ stageDays(s) }}</span>
                            <span><span class="font-medium">{{ s.name }}</span><template v-if="s.actions?.length"> — {{ s.actions.map((x) => x.description ?? x.type).join(', ') }}</template></span>
                        </div>
                    </div>
                    <p v-if="tp.additives.length" class="text-muted-foreground">
                        {{ t('Doses') }}: {{ tp.additives.map((a) => `${a.name} ${qty(a.dose)}${BASIS[a.basis] ?? ''}`).join(', ') }}
                    </p>
                    <p class="text-muted-foreground">{{ t('Used by :n lots', { n: tp.lots_in_use ?? 0 }) }}</p>
                </Card>
                <p v-if="templates.length === 0" class="border border-dashed border-border p-10 text-center text-sm text-muted-foreground lg:col-span-2">{{ t('No protocols yet.') }}</p>
            </div>
        </div>
        <ProtocolFormPanel :open="panel" :template="editing" :duplicate="duplicating" :products="products" @close="panel = false" />
</template>
