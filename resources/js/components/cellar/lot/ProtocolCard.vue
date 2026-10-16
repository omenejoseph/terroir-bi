<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { FermentationTemplate, WineLotDetail } from '@/types/cellar';

/**
 * Fermentation protocol on a lot: pick the template, generate today's tasks
 * from it (board cards), or plan its additive doses for the cellar team (a
 * round on Today, per vessel, from the template's structured doses).
 */
const props = defineProps<{ lot: WineLotDetail; templates: FermentationTemplate[]; canManage: boolean }>();

const { t } = useTranslations();
const planDate = ref(new Date().toISOString().slice(0, 10));

const options = computed(() => [{ value: '', label: t('No template') }, ...props.templates.filter((tp) => tp.is_active || tp.id === props.lot.fermentation_template_id).map((tp) => ({ value: tp.id, label: tp.name }))]);
const template = computed(() => props.templates.find((tp) => tp.id === props.lot.fermentation_template_id) ?? null);

function assign(id: string): void {
    router.post(`/cellar/lots/${props.lot.id}/protocol`, { fermentation_template_id: id || null }, { preserveScroll: true });
}
function generate(): void {
    router.post(`/cellar/lots/${props.lot.id}/protocol/generate`, {}, { preserveScroll: true });
}
function planAdditions(): void {
    router.post(`/cellar/lots/${props.lot.id}/protocol/plan-additions`, { planned_for: planDate.value }, { preserveScroll: true });
}
</script>

<template>
    <Card class="flex flex-col gap-3 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="text-sm font-semibold">{{ t('Fermentation protocol') }}</h3>
            <Select v-if="canManage" :model-value="lot.fermentation_template_id ?? ''" :options="options" class="w-56" @update:model-value="assign" />
            <span v-else class="text-sm">{{ template?.name ?? t('No template') }}</span>
        </div>
        <p v-if="template?.description" class="text-xs text-muted-foreground">{{ template.description }}</p>
        <div v-if="template && canManage" class="flex flex-wrap items-center gap-2">
            <Button size="sm" variant="outline" @click="generate">{{ t("Generate today's tasks") }}</Button>
            <span class="mx-1 h-4 w-px bg-border" />
            <Input v-model="planDate" type="date" class="h-7 w-36 text-xs" />
            <Button size="sm" variant="outline" :disabled="template.additives.length === 0" @click="planAdditions">{{ t('Plan protocol additions (cellar team)') }}</Button>
            <span v-if="template.additives.length === 0" class="text-xs text-muted-foreground">{{ t('This protocol has no additive doses.') }}</span>
        </div>
    </Card>
</template>
