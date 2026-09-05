<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Megaphone } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption } from '@/types/admin';

/**
 * Broadcast — port of App\Filament\Pages\Broadcast. No history/log page —
 * Filament's had none either, just the compose action.
 */
const props = defineProps<{ tenantOptions: AdminOption[] }>();

const { t } = useTranslations();

const composeOpen = ref(false);

const form = useForm({
    title: '',
    body: '',
    tenants: [] as string[],
});

watch(composeOpen, (open) => {
    if (!open) return;

    form.reset();
    form.clearErrors();
});

function toggleTenant(id: string, checked: boolean): void {
    form.tenants = checked ? [...form.tenants, id] : form.tenants.filter((t) => t !== id);
}

function submit(): void {
    form.post(`${ADMIN_BASE}/broadcast`, {
        preserveScroll: true,
        onSuccess: () => (composeOpen.value = false),
    });
}
</script>

<template>
    <AdminLayout :title="t('Broadcast')">
        <div class="space-y-5">
            <PageHeader :title="t('Broadcast')">
                <template #actions>
                    <Button size="sm" @click="composeOpen = true">
                        <Megaphone class="size-3.5" :stroke-width="1.5" />
                        {{ t('Compose announcement') }}
                    </Button>
                </template>
            </PageHeader>

            <Card class="p-6 text-sm text-muted-foreground">
                {{ t('Sends an in-app notification and a web push to every active member of the chosen tenants — leave the audience empty to reach everyone.') }}
            </Card>
        </div>

        <Dialog :open="composeOpen" :title="t('Compose announcement')" @close="composeOpen = false">
            <form id="broadcast-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <FormField :label="t('Title')" required :error="form.errors.title">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.title" :invalid="invalid" maxlength="120" />
                    </template>
                </FormField>

                <FormField :label="t('Message')" :error="form.errors.body">
                    <template #default="{ id, invalid }">
                        <Textarea :id="id" v-model="form.body" :rows="3" :invalid="invalid" maxlength="500" />
                    </template>
                </FormField>

                <FormField :label="t('Audience')" :hint="t('Leave empty to send to every tenant.')" :error="form.errors.tenants">
                    <template #default>
                        <div class="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-border p-3">
                            <Checkbox
                                v-for="tenant in props.tenantOptions"
                                :key="tenant.value"
                                :model-value="form.tenants.includes(tenant.value)"
                                :label="tenant.label"
                                @update:model-value="toggleTenant(tenant.value, $event)"
                            />
                            <p v-if="props.tenantOptions.length === 0" class="text-xs text-muted-foreground">
                                {{ t('No tenants yet.') }}
                            </p>
                        </div>
                    </template>
                </FormField>
            </form>

            <template #footer>
                <Button variant="outline" @click="composeOpen = false">{{ t('Cancel') }}</Button>
                <Button type="submit" form="broadcast-form" :disabled="form.processing">{{ t('Send') }}</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>
