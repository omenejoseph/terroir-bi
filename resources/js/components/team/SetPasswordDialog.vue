<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { TeamMember } from '@/types/team';

/**
 * Team page's "Set password" — deliberately a separate form from "Manage
 * roles": this takes effect immediately with no confirmation step by the
 * member (App\Actions\Members\SetMemberPasswordAction), unlike the
 * self-service email-reset flow, so it's kept as its own explicit action
 * rather than folded into the same dialog as roles/status.
 */
const props = defineProps<{ open: boolean; member: TeamMember | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({ password: '', password_confirmation: '' });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    if (props.member === null) return;

    form.patch(`/settings/team/${props.member.id}/password`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="open" :title="t('Set password')" @close="emit('close')">
        <form v-if="member" id="set-password-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <p class="text-sm text-muted-foreground">
                {{ t('Sets a new password for :name immediately — they are not notified.', { name: member.name }) }}
            </p>

            <FormField v-slot="{ id, invalid }" :label="t('New password')" required :error="form.errors.password">
                <Input :id="id" v-model="form.password" :invalid="invalid" type="password" autocomplete="new-password" />
            </FormField>

            <FormField v-slot="{ id }" :label="t('Confirm password')" required>
                <Input :id="id" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="set-password-form" :disabled="form.processing">
                {{ form.processing ? t('Saving…') : t('Set password') }}
            </Button>
        </template>
    </Dialog>
</template>
