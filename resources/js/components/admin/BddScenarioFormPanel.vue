<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminBddScenarioDetail, AdminBddScenarioSummary } from '@/types/admin';

const PLACEHOLDER = `Scenario: Creating an order deducts stock immediately
  Given "R3 2025" has 100 bottles in stock
  When a non-backorder order for 24 bottles of "R3 2025" is created
  Then an ORDER_DEDUCT movement of -24 bottles is recorded referencing the order number
  And current stock of "R3 2025" is 76 bottles`;

/**
 * Create/edit — port of App\Filament\Resources\BddScenarios\Schemas\BddScenarioForm.
 */
const props = defineProps<{
    open: boolean;
    scenario: (AdminBddScenarioSummary & Partial<AdminBddScenarioDetail>) | null;
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const isEdit = computed(() => props.scenario !== null);

const form = useForm({
    title: '',
    gherkin: '',
    is_active: true,
});

watch(
    () => [props.open, props.scenario?.id],
    () => {
        if (!props.open) return;

        const s = props.scenario;

        form.defaults({
            title: s?.title ?? '',
            gherkin: s?.gherkin ?? '',
            is_active: s?.is_active ?? true,
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (isEdit.value) {
        form.patch(`${ADMIN_BASE}/bdd-scenarios/${props.scenario!.id}`, options);
    } else {
        form.post(`${ADMIN_BASE}/bdd-scenarios`, options);
    }
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit scenario') : t('New scenario')" @close="emit('close')">
        <form id="bdd-scenario-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <FormField
                :label="t('Title')"
                required
                :error="form.errors.title"
            >
                <template #default="{ id, invalid }">
                    <Input :id="id" v-model="form.title" :invalid="invalid" placeholder="ORD-001 — Stock is committed at order creation" />
                </template>
            </FormField>

            <FormField
                :label="t('Gherkin')"
                required
                :hint="t('Plain Given/When/Then. On run, an AI agent executes it live against the granted operations (sandboxed, always rolled back); money in EUR is fine (€12.00) — the agent converts to minor units.')"
                :error="form.errors.gherkin"
            >
                <template #default="{ id, invalid }">
                    <Textarea
                        :id="id"
                        v-model="form.gherkin"
                        :rows="14"
                        :invalid="invalid"
                        :placeholder="PLACEHOLDER"
                        class="font-mono text-sm"
                    />
                </template>
            </FormField>

            <SwitchRow v-model="form.is_active" :label="t('Active (included in Run all)')" />
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="bdd-scenario-form" :disabled="form.processing">
                {{ isEdit ? t('Save changes') : t('Create scenario') }}
            </Button>
        </template>
    </SidePanel>
</template>
