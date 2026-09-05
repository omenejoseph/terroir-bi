<script setup lang="ts">
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminUserDetail } from '@/types/admin';

/**
 * User — Show. Port of App\Filament\Resources\Users\UserResource::infolist():
 * a summary section plus a repeatable "Tenants" section over the user's
 * memberships.
 */
defineProps<{ user: AdminUserDetail }>();

const { t } = useTranslations();
</script>

<template>
    <AdminLayout :title="user.name">
        <div class="space-y-5">
            <PageHeader :title="user.name">
                <template #actions>
                    <Link
                        :href="`${ADMIN_BASE}/users`"
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                        {{ t('Back to users') }}
                    </Link>
                </template>
            </PageHeader>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('User') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Email') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ user.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Platform admin') }}</dt>
                        <dd class="mt-0.5">
                            <ShieldCheck
                                v-if="user.is_platform_admin"
                                class="size-4 text-muted-foreground"
                                :stroke-width="1.5"
                            />
                            <span v-else class="text-muted-foreground">{{ t('No') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Created') }}</dt>
                        <dd class="mt-0.5 text-foreground">
                            {{ user.created_at ? new Date(user.created_at).toLocaleDateString() : '—' }}
                        </dd>
                    </div>
                </dl>
            </Card>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Tenants') }}</h3>
                <div v-if="user.memberships.length === 0" class="text-sm text-muted-foreground">
                    {{ t('Not a member of any tenant.') }}
                </div>
                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="(membership, i) in user.memberships"
                        :key="i"
                        class="grid grid-cols-3 items-center gap-4 py-3 text-sm first:pt-0 last:pb-0"
                    >
                        <span class="text-foreground">{{ membership.tenant_name ?? '—' }}</span>
                        <span class="flex flex-wrap gap-1">
                            <Badge v-for="role in membership.roles" :key="role">{{ role }}</Badge>
                        </span>
                        <span><Badge variant="outline">{{ membership.status }}</Badge></span>
                    </li>
                </ul>
            </Card>
        </div>
    </AdminLayout>
</template>
