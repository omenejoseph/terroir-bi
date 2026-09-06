<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { RoleOption, TeamMember } from '@/types/team';

/**
 * Team page's "Manage roles" — roles + status together (App\Enums\TenantRole
 * / MembershipStatus), the same two fields Web\Admin\TenantMemberController's
 * own edit form exposes, backed by the same UpdateMemberAction. "Suspended"
 * is this app's "blocked" — MembershipStatus has no separate blocked state.
 */
const props = defineProps<{ open: boolean; member: TeamMember | null; roleOptions: RoleOption[]; isSelf: boolean }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const STATUS_OPTIONS = computed(() => [
    { value: 'active', label: t('Active') },
    { value: 'suspended', label: t('Suspended (blocked)') },
]);

const form = useForm({ roles: [] as string[], status: 'active' });

watch(
    () => props.member,
    (member) => {
        if (member === null) return;
        form.roles = [...member.roles];
        form.status = member.status;
        form.clearErrors();
    },
    { immediate: true },
);

function toggleRole(value: string, checked: boolean): void {
    form.roles = checked ? [...form.roles, value] : form.roles.filter((r) => r !== value);
}

/**
 * Admin (App\Enums\TenantRole::Admin) grants the wildcard capability — see
 * App\Authorization\RoleCapabilities::map() — so it already means full
 * access to everything, Cellar/Orders/Team/all of it, with no need for any
 * other role to also be checked. Surfaced here because "Admin is checked
 * but nothing else is" reads, at a glance, like a partial grant rather than
 * a complete one.
 */
const isAdminChecked = computed(() => form.roles.includes('ADMIN'));

function submit(): void {
    if (props.member === null) return;

    form.patch(`/settings/team/${props.member.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="open" :title="t('Manage roles')" @close="emit('close')">
        <form v-if="member" id="edit-member-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <p class="text-sm text-muted-foreground">{{ member.name }} · {{ member.email }}</p>

            <FormField :label="t('Roles')" required :error="form.errors.roles">
                <p v-if="isAdminChecked" class="mb-2 text-xs text-muted-foreground">
                    {{ t('Admin already has full access to everything — the roles below add nothing more while this is checked.') }}
                </p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                    <Checkbox
                        v-for="option in roleOptions"
                        :key="option.value"
                        :model-value="form.roles.includes(option.value)"
                        :label="option.label"
                        @update:model-value="toggleRole(option.value, $event)"
                    />
                </div>
            </FormField>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Status')"
                :error="form.errors.status"
                :hint="isSelf ? t('You cannot suspend your own account.') : undefined"
            >
                <Select :id="id" v-model="form.status" :invalid="invalid" :disabled="isSelf" :options="STATUS_OPTIONS" />
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="edit-member-form" :disabled="form.processing">
                {{ form.processing ? t('Saving…') : t('Save changes') }}
            </Button>
        </template>
    </Dialog>
</template>
