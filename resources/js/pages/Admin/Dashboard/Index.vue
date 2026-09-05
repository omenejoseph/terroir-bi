<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * Tier 0 placeholder — proves the /admin routing, layout and auth plumbing
 * end to end. The 5 widgets (StatsOverview, signups chart, plan-distribution
 * chart, tenants-needing-attention table, most-active-tenants table) land in
 * Tier 3; see App\Filament\Widgets\** for the port source and the plan for
 * the component breakdown.
 */
const { t } = useTranslations();
</script>

<template>
    <AdminLayout :title="t('Admin')">
        <div class="space-y-6">
            <PageHeader :title="t('Admin dashboard')" />

            <Card class="p-6">
                <p class="text-sm text-muted-foreground">
                    {{ t('Platform overview widgets are on their way — this section is being ported from the retired Filament back office.') }}
                </p>
            </Card>
        </div>
    </AdminLayout>
</template>
