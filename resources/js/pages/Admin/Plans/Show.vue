<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CreditCard, PencilLine } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import PlanFormPanel from '@/components/admin/PlanFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminPlan, AdminPlanTenant } from '@/types/admin';

/**
 * Plan — Show. Port of App\Filament\Resources\Plans\Schemas\PlanInfolist +
 * the tenants relation manager (read-only here).
 */
const props = defineProps<{
    plan: AdminPlan;
    tenants: AdminPlanTenant[];
    moduleOptions: AdminOption[];
    canCreateStripePrice: boolean;
}>();

const { t } = useTranslations();

const formOpen = ref(false);

function createStripePrice(): void {
    if (!confirm(t("Create a Stripe product + recurring price from this plan's amount and link it to the plan?"))) {
        return;
    }

    router.post(`${ADMIN_BASE}/plans/${props.plan.id}/create-stripe-price`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="plan.name">
        <div class="space-y-5">
            <PageHeader :title="plan.name">
                <template #actions>
                    <Link
                        :href="`${ADMIN_BASE}/plans`"
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                        {{ t('Back to plans') }}
                    </Link>
                    <Button v-if="canCreateStripePrice" variant="outline" size="sm" @click="createStripePrice">
                        <CreditCard class="size-3.5" :stroke-width="1.5" />
                        {{ t('Set price in Stripe') }}
                    </Button>
                    <Button size="sm" @click="formOpen = true">
                        <PencilLine class="size-3.5" :stroke-width="1.5" />
                        {{ t('Edit') }}
                    </Button>
                </template>
            </PageHeader>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Plan') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Slug') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.slug }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Price') }}</dt>
                        <dd class="mt-0.5">
                            <Badge :variant="plan.price_major === null ? 'neutral' : 'solid'">
                                {{ plan.price_major === null ? t('Free / internal') : `${plan.price_major} ${plan.currency} / ${plan.interval}` }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Tenants on this plan') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.tenants_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Active') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.is_active ? t('Yes') : t('No') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Public') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.is_public ? t('Yes') : t('No') }}</dd>
                    </div>
                </dl>
            </Card>

            <Card class="p-6">
                <h3 class="mb-1 text-sm font-semibold text-foreground">{{ t('Modules') }}</h3>
                <p class="mb-4 text-xs text-muted-foreground">{{ t('Tenants on this plan see only these modules.') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <Badge v-for="m in plan.modules" :key="m">{{ m }}</Badge>
                    <span v-if="plan.modules.length === 0" class="text-sm text-muted-foreground">{{ t('— no modules —') }}</span>
                </div>
            </Card>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Billing') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Stripe price ID') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.stripe_price_id ?? t('— free / internal —') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Trial (days)') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.trial_days }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Full-access grace (days)') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.grace_full_days }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Read-only grace (days)') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ plan.grace_readonly_days }}</dd>
                    </div>
                </dl>
            </Card>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Tenants') }}</h3>
                <div v-if="tenants.length === 0" class="text-sm text-muted-foreground">
                    {{ t('No tenants on this plan yet.') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">{{ t('Name') }}</th>
                                <th class="py-2 font-medium">{{ t('Slug') }}</th>
                                <th class="py-2 font-medium">{{ t('Status') }}</th>
                                <th class="py-2 font-medium">{{ t('Stripe') }}</th>
                                <th class="py-2"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tenant in tenants" :key="tenant.id" class="border-b border-border last:border-b-0">
                                <td class="py-2.5 text-foreground">{{ tenant.name }}</td>
                                <td class="py-2.5 text-muted-foreground">{{ tenant.slug }}</td>
                                <td class="py-2.5"><Badge>{{ tenant.status }}</Badge></td>
                                <td class="py-2.5 text-muted-foreground">{{ tenant.stripe_status ?? '—' }}</td>
                                <td class="py-2.5 text-right">
                                    <Link
                                        :href="`${ADMIN_BASE}/tenants/${tenant.id}`"
                                        class="text-primary hover:underline"
                                    >
                                        {{ t('Open') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <PlanFormPanel :open="formOpen" :plan="plan" :module-options="moduleOptions" @close="formOpen = false" />
    </AdminLayout>
</template>
