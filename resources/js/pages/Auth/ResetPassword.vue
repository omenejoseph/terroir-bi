<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import AuthLayout from '@/layouts/AuthLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import Input from '@/components/ui/Input.vue';
import InputError from '@/components/ui/InputError.vue';
import Label from '@/components/ui/Label.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * The landing page an admin-triggered reset email points to — see
 * App\Http\Controllers\Web\Auth\PasswordResetController.
 */
const props = defineProps<{
    token: string;
    email: string | null;
}>();

const { t } = useTranslations();

const form = useForm({
    token: props.token,
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout :title="t('Reset your password')">
        <Card>
            <CardHeader>
                <CardTitle>{{ t('Set a new password') }}</CardTitle>
            </CardHeader>

            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="email">{{ t('Email') }}</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            :invalid="Boolean(form.errors.email)"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="space-y-2">
                        <Label for="password">{{ t('New password') }}</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            :invalid="Boolean(form.errors.password)"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="space-y-2">
                        <Label for="password_confirmation">{{ t('Confirm new password') }}</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                        />
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? t('Saving…') : t('Reset password') }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </AuthLayout>
</template>
