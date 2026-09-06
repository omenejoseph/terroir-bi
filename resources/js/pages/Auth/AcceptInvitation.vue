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
 * Public landing page for a Team invitation link (Web\TeamInvitationController
 * generates it; no email is sent — the admin shares it directly, so this is
 * reached from whatever channel they used, not a mail client).
 *
 * `needsProfile` distinguishes a brand-new email (needs a name + a chosen
 * password to create the account) from an existing one (needs THEIR password
 * — proving whoever is holding this link actually is that account, since the
 * token alone only proves someone was invited).
 */
const props = defineProps<{
    valid: boolean;
    token?: string;
    email?: string;
    tenantName?: string | null;
    roleLabels?: string[];
    needsProfile?: boolean;
}>();

const { t } = useTranslations();

const form = useForm({
    token: props.token ?? '',
    first_name: '',
    middle_name: '',
    last_name: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/invitations/accept', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout :title="t('Accept invitation')">
        <Card>
            <template v-if="!valid">
                <CardHeader>
                    <CardTitle>{{ t('Invitation not found') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-sm text-muted-foreground">
                        {{ t('This invitation is invalid or has expired. Ask whoever invited you to send a new one.') }}
                    </p>
                </CardContent>
            </template>

            <template v-else>
                <CardHeader>
                    <CardTitle>{{ t('Join :tenant', { tenant: tenantName ?? 'Terroir' }) }}</CardTitle>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t("You've been invited as :roles to :email.", {
                                roles: (roleLabels ?? []).join(', '),
                                email: email ?? '',
                            })
                        }}
                    </p>
                </CardHeader>

                <CardContent>
                    <form class="space-y-4" @submit.prevent="submit">
                        <template v-if="needsProfile">
                            <div class="space-y-2">
                                <Label for="first_name">{{ t('First name') }}</Label>
                                <Input id="first_name" v-model="form.first_name" :invalid="Boolean(form.errors.first_name)" />
                                <InputError :message="form.errors.first_name" />
                            </div>

                            <div class="space-y-2">
                                <Label for="last_name">{{ t('Last name') }}</Label>
                                <Input id="last_name" v-model="form.last_name" :invalid="Boolean(form.errors.last_name)" />
                                <InputError :message="form.errors.last_name" />
                            </div>

                            <div class="space-y-2">
                                <Label for="password">{{ t('Choose a password') }}</Label>
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
                                <Label for="password_confirmation">{{ t('Confirm password') }}</Label>
                                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                            </div>
                        </template>

                        <div v-else class="space-y-2">
                            <p class="text-xs text-muted-foreground">
                                {{ t('An account already exists for this email — enter its password to accept.') }}
                            </p>
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

                        <Button type="submit" class="w-full" :disabled="form.processing">
                            {{ form.processing ? t('Joining…') : t('Accept invitation') }}
                        </Button>
                    </form>
                </CardContent>
            </template>
        </Card>
    </AuthLayout>
</template>
