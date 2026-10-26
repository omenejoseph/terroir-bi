<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { CATEGORY_LABELS } from '@/lib/support';
import type { SupportTicketCategory } from '@/lib/support';

/**
 * "New request" — the form a member fills in to reach the platform's support
 * team (Web\SupportTicketController::store). `pageUrl` is where in the app
 * they were when they asked, so support can open the same screen.
 */
const props = defineProps<{ open: boolean; pageUrl?: string | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({
    subject: '',
    category: 'question' as SupportTicketCategory,
    body: '',
});

const categoryOptions = computed(() =>
    (Object.keys(CATEGORY_LABELS) as SupportTicketCategory[]).map((value) => ({ value, label: t(CATEGORY_LABELS[value]) })),
);

function submit(): void {
    form.transform((data) => ({ ...data, page_url: props.pageUrl ?? null })).post('/support', {
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Contact support')" :subtitle="t('Our team replies here and by email.')" @close="emit('close')">
        <form id="new-ticket-form" class="space-y-4" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('What is it about?')" required :error="form.errors.category">
                <Select :id="id" v-model="form.category" :invalid="invalid" :options="categoryOptions" />
            </FormField>

            <FormField v-slot="{ id, invalid }" :label="t('Subject')" required :error="form.errors.subject">
                <Input :id="id" v-model="form.subject" :invalid="invalid" maxlength="200" :placeholder="t('e.g. Bulk import skips some rows')" />
            </FormField>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Describe it')"
                :hint="t('What were you trying to do, and what happened instead? Steps to reproduce help us fix it faster.')"
                required
                :error="form.errors.body"
            >
                <Textarea :id="id" v-model="form.body" :invalid="invalid" :rows="8" maxlength="10000" />
            </FormField>
        </form>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
                <Button type="submit" form="new-ticket-form" :disabled="form.processing">
                    {{ form.processing ? t('Sending…') : t('Send to support') }}
                </Button>
            </div>
        </template>
    </SidePanel>
</template>
