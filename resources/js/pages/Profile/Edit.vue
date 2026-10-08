<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * "My profile": the signed-in person's own name and password (Web\ProfileController). Open to every
 * member, since it only ever touches their own account. Email is shown but not editable: it is the
 * sign-in identity shared across every organisation they belong to.
 *
 * While an admin is impersonating a team member the page can be read but every form is switched
 * off (and the server refuses the writes too), so nobody can rewrite or lock out the person
 * they are looking at.
 */
const props = defineProps<{
    profile: { first_name: string; middle_name: string | null; last_name: string; email: string };
    readOnly: boolean;
}>();

const { t } = useTranslations();

const details = useForm({
    first_name: props.profile.first_name,
    middle_name: props.profile.middle_name ?? '',
    last_name: props.profile.last_name,
});

function saveDetails(): void {
    details
        .transform((data) => ({ ...data, middle_name: data.middle_name.trim() === '' ? null : data.middle_name }))
        .patch('/profile', { preserveScroll: true });
}

const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function savePassword(): void {
    password.put('/profile/password', {
        preserveScroll: true,
        // Never leave a typed password sitting in the form, success or not.
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AppLayout :title="t('My profile')">
        <div class="max-w-2xl space-y-5">
            <PageHeader :title="t('My profile')" :description="t('Your name and password.')" />

            <Callout v-if="readOnly" :title="t('Read only while impersonating')" tone="warning">
                {{ t('You are viewing this account as another person. Stop impersonating to change anything here.') }}
            </Callout>

            <form class="space-y-5" @submit.prevent="saveDetails">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Personal details') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ id, invalid }" :label="t('First name')" required :error="details.errors.first_name">
                                <Input :id="id" v-model="details.first_name" :invalid="invalid" :disabled="readOnly" maxlength="255" autocomplete="given-name" />
                            </FormField>

                            <FormField v-slot="{ id, invalid }" :label="t('Last name')" required :error="details.errors.last_name">
                                <Input :id="id" v-model="details.last_name" :invalid="invalid" :disabled="readOnly" maxlength="255" autocomplete="family-name" />
                            </FormField>
                        </div>

                        <FormField v-slot="{ id, invalid }" :label="t('Middle name')" :error="details.errors.middle_name">
                            <Input :id="id" v-model="details.middle_name" :invalid="invalid" :disabled="readOnly" maxlength="255" autocomplete="additional-name" />
                        </FormField>

                        <FormField v-slot="{ id }" :label="t('Email')" :hint="t('The email you sign in with. Ask an administrator if it needs to change.')">
                            <Input :id="id" :model-value="profile.email" type="email" disabled />
                        </FormField>
                    </CardContent>
                </Card>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="readOnly || details.processing || !details.isDirty">
                        {{ details.processing ? t('Saving…') : t('Save changes') }}
                    </Button>
                </div>
            </form>

            <form class="space-y-5" @submit.prevent="savePassword">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Change password') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p class="text-xs text-muted-foreground">
                            {{ t('Use at least 8 characters. Changing your password signs you out of any other apps or devices using the API.') }}
                        </p>

                        <FormField v-slot="{ id, invalid }" :label="t('Current password')" required :error="password.errors.current_password">
                            <Input :id="id" v-model="password.current_password" type="password" :invalid="invalid" :disabled="readOnly" autocomplete="current-password" />
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ id, invalid }" :label="t('New password')" required :error="password.errors.password">
                                <Input :id="id" v-model="password.password" type="password" :invalid="invalid" :disabled="readOnly" autocomplete="new-password" />
                            </FormField>

                            <FormField v-slot="{ id }" :label="t('Confirm new password')" required>
                                <Input :id="id" v-model="password.password_confirmation" type="password" :disabled="readOnly" autocomplete="new-password" />
                            </FormField>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="readOnly || password.processing || password.current_password === '' || password.password === ''"
                    >
                        {{ password.processing ? t('Saving…') : t('Update password') }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
