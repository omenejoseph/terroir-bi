<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Link as LinkIcon, Mail, PencilLine, Plus, Trash2 } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import OnboardingLinkDialog from '@/components/admin/OnboardingLinkDialog.vue';
import TenantMemberFormPanel from '@/components/admin/TenantMemberFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminTenant, AdminTenantMember } from '@/types/admin';
import type { MenuItem } from '@/types/ui';

/**
 * Tenant — Show. Port of App\Filament\Resources\Tenants\Schemas\TenantInfolist
 * + the Members relation manager. Status/plan changes live here (inline
 * selects + a dedicated PATCH each) rather than a separate Edit form, since
 * Filament's Edit form had only those two fields.
 */
const props = defineProps<{
    tenant: AdminTenant;
    members: AdminTenantMember[];
    statusOptions: AdminOption[];
    planOptions: AdminOption[];
    roleOptions: AdminOption[];
}>();

const { t } = useTranslations();

const statusForm = useForm({ status: props.tenant.status });
const planForm = useForm({ plan_id: props.tenant.plan_id ?? '' });

function updateStatus(): void {
    statusForm.patch(`${ADMIN_BASE}/tenants/${props.tenant.id}/status`, { preserveScroll: true });
}

function updatePlan(): void {
    planForm
        .transform((data) => ({ plan_id: data.plan_id === '' ? null : data.plan_id }))
        .patch(`${ADMIN_BASE}/tenants/${props.tenant.id}/plan`, { preserveScroll: true });
}

const onboardingOpen = ref(false);

function emailLink(): void {
    if (!confirm(t('Email a Stripe subscription link to :name?', { name: props.tenant.name }))) return;

    router.post(`${ADMIN_BASE}/tenants/${props.tenant.id}/email-billing-link`, {}, { preserveScroll: true });
}

const memberFormOpen = ref(false);
const editingMember = ref<AdminTenantMember | null>(null);

function addMember(): void {
    editingMember.value = null;
    memberFormOpen.value = true;
}

function editMember(member: AdminTenantMember): void {
    editingMember.value = member;
    memberFormOpen.value = true;
}

const memberActions: MenuItem[] = [
    { key: 'edit', label: t('Edit'), icon: PencilLine },
    { key: 'delete', label: t('Delete'), icon: Trash2, destructive: true },
];

function onMemberAction(key: string, member: AdminTenantMember): void {
    if (key === 'edit') {
        editMember(member);

        return;
    }

    if (key === 'delete') removeMember(member);
}

function removeMember(member: AdminTenantMember): void {
    if (!confirm(t('Remove :name from this tenant?', { name: member.name }))) return;

    router.delete(`${ADMIN_BASE}/tenants/${props.tenant.id}/members/${member.id}`, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="tenant.name">
        <div class="space-y-5">
            <PageHeader :title="tenant.name">
                <template #actions>
                    <Link
                        :href="`${ADMIN_BASE}/tenants`"
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                        {{ t('Back to tenants') }}
                    </Link>
                    <template v-if="tenant.needs_subscription">
                        <Button variant="outline" size="sm" @click="onboardingOpen = true">
                            <LinkIcon class="size-3.5" :stroke-width="1.5" />
                            {{ t('Generate subscription link') }}
                        </Button>
                        <Button variant="outline" size="sm" @click="emailLink">
                            <Mail class="size-3.5" :stroke-width="1.5" />
                            {{ t('Email subscription link') }}
                        </Button>
                    </template>
                </template>
            </PageHeader>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Tenant') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Slug') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ tenant.slug }}</dd>
                    </div>
                    <div />
                    <div>
                        <dt class="mb-1 text-xs text-muted-foreground">{{ t('Status') }}</dt>
                        <dd class="flex items-center gap-2">
                            <Select v-model="statusForm.status" :options="statusOptions" class="w-40" />
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="statusForm.processing || statusForm.status === tenant.status"
                                @click="updateStatus"
                            >
                                {{ t('Update') }}
                            </Button>
                        </dd>
                    </div>
                    <div>
                        <dt class="mb-1 text-xs text-muted-foreground">{{ t('Plan') }}</dt>
                        <dd class="flex items-center gap-2">
                            <Select
                                v-model="planForm.plan_id"
                                :options="planOptions"
                                :placeholder="t('No plan (unrestricted)')"
                                class="w-48"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="planForm.processing || planForm.plan_id === (tenant.plan_id ?? '')"
                                @click="updatePlan"
                            >
                                {{ t('Update') }}
                            </Button>
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-muted-foreground">{{ t('Plan modules') }}</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            <Badge v-for="m in tenant.plan?.modules ?? []" :key="m">{{ m }}</Badge>
                            <span v-if="!tenant.plan?.modules.length" class="text-sm text-muted-foreground">
                                {{ t('— all modules (no plan) —') }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </Card>

            <Card class="p-6">
                <h3 class="mb-1 text-sm font-semibold text-foreground">{{ t('Stripe subscription') }}</h3>
                <p class="mb-4 text-xs text-muted-foreground">
                    {{ t('Synced from Stripe via webhooks. Drive it with the actions above.') }}
                </p>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Effective access') }}</dt>
                        <dd class="mt-0.5"><Badge>{{ tenant.access }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Stripe status') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ tenant.subscription?.stripe_status ?? t('— no subscription —') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Customer ID') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ tenant.subscription?.stripe_customer_id ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Subscription ID') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ tenant.subscription?.stripe_subscription_id ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Price ID') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ tenant.subscription?.stripe_price_id ?? t('— free / internal —') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Trial ends') }}</dt>
                        <dd class="mt-0.5 text-foreground">
                            {{ tenant.subscription?.trial_ends_at ? new Date(tenant.subscription.trial_ends_at).toLocaleString() : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Current period ends') }}</dt>
                        <dd class="mt-0.5 text-foreground">
                            {{ tenant.subscription?.current_period_end ? new Date(tenant.subscription.current_period_end).toLocaleString() : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Canceled at') }}</dt>
                        <dd class="mt-0.5 text-foreground">
                            {{ tenant.subscription?.canceled_at ? new Date(tenant.subscription.canceled_at).toLocaleString() : '—' }}
                        </dd>
                    </div>
                </dl>
            </Card>

            <Card class="p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-foreground">{{ t('Members') }}</h3>
                    <Button size="sm" @click="addMember">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('Add member') }}
                    </Button>
                </div>
                <div v-if="members.length === 0" class="text-sm text-muted-foreground">
                    {{ t('No members yet.') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">{{ t('Name') }}</th>
                                <th class="py-2 font-medium">{{ t('Email') }}</th>
                                <th class="py-2 font-medium">{{ t('Roles') }}</th>
                                <th class="py-2 font-medium">{{ t('Status') }}</th>
                                <th class="py-2"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="member in members" :key="member.id" class="border-b border-border last:border-b-0">
                                <td class="py-2.5 text-foreground">{{ member.name }}</td>
                                <td class="py-2.5 text-muted-foreground">{{ member.email }}</td>
                                <td class="py-2.5">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge v-for="role in member.roles" :key="role">{{ role }}</Badge>
                                    </div>
                                </td>
                                <td class="py-2.5"><Badge variant="outline">{{ member.status }}</Badge></td>
                                <td class="py-2.5">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            :items="memberActions"
                                            :label="t('Actions for :name', { name: member.name })"
                                            @select="onMemberAction($event, member)"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <TenantMemberFormPanel
            :open="memberFormOpen"
            :tenant-id="tenant.id"
            :member="editingMember"
            :role-options="roleOptions"
            @close="memberFormOpen = false"
        />

        <OnboardingLinkDialog :open="onboardingOpen" :tenant-id="tenant.id" @close="onboardingOpen = false" />
    </AdminLayout>
</template>
