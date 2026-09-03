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

const { t } = useTranslations();

/**
 * `useForm` keeps the password out of Inertia's history state and surfaces
 * server-side ValidationException messages on `form.errors` without any
 * client-side mirror of the rules.
 */
const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout :title="t('Sign in')">
        <Card>
            <CardHeader>
                <CardTitle>{{ t('Sign in to Terroir') }}</CardTitle>
                <p class="text-sm text-muted-foreground">{{ t('Use your work email address.') }}</p>
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
                        <Label for="password">{{ t('Password') }}</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            :invalid="Boolean(form.errors.password)"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.remember" type="checkbox" class="border-input" />
                        {{ t('Keep me signed in') }}
                    </label>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? t('Signing in…') : t('Sign in') }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </AuthLayout>
</template>
