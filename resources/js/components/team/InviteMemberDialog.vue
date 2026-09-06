<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { RoleOption } from '@/types/team';

/**
 * Team page's "Invite" (capability `invitations.manage`). No email is sent —
 * submitting hands back a one-time copyable link (Web\TeamInvitationController
 * flashes it; the page reads it off `$page.props.flash.inviteLink`), the
 * same "share it yourself" shape as Customers' own "Generate Order Link".
 */
const props = defineProps<{ open: boolean; roleOptions: RoleOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({ email: '', roles: [] as string[] });

function toggleRole(value: string, checked: boolean): void {
    form.roles = checked ? [...form.roles, value] : form.roles.filter((r) => r !== value);
}

/** See EditMemberDialog's own note — Admin's wildcard capability means no other role adds anything. */
const isAdminChecked = computed(() => form.roles.includes('ADMIN'));

function submit(): void {
    form.post('/settings/team/invitations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
}
</script>

<template>
    <Dialog :open="open" :title="t('Invite a teammate')" @close="emit('close')">
        <form id="invite-member-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Email')" required :error="form.errors.email">
                <Input :id="id" v-model="form.email" :invalid="invalid" type="email" autocomplete="email" />
            </FormField>

            <FormField :label="t('Roles')" required :error="form.errors.roles">
                <p v-if="isAdminChecked" class="mb-2 text-xs text-muted-foreground">
                    {{ t('Admin already has full access to everything — the roles below add nothing more while this is checked.') }}
                </p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                    <Checkbox
                        v-for="option in props.roleOptions"
                        :key="option.value"
                        :model-value="form.roles.includes(option.value)"
                        :label="option.label"
                        @update:model-value="toggleRole(option.value, $event)"
                    />
                </div>
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="invite-member-form" :disabled="form.processing">
                {{ form.processing ? t('Sending…') : t('Create invite link') }}
            </Button>
        </template>
    </Dialog>
</template>
