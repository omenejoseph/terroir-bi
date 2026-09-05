<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowUpCircle, Ban, Plus } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import PlatformAdminFormPanel from '@/components/admin/PlatformAdminFormPanel.vue';
import PromotePlatformAdminDialog from '@/components/admin/PromotePlatformAdminDialog.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { PlatformAdmin } from '@/types/admin';
import type { ComboboxOption } from '@/types/ui';

/**
 * Platform Admins — port of App\Filament\Resources\PlatformAdmins\**. Create,
 * promote or revoke; no plain edit (matches the Filament resource, which has
 * no edit/view page either).
 */
const props = defineProps<{
    admins: PlatformAdmin[];
    candidates: ComboboxOption[];
    currentUserId: string | null;
}>();

const { t } = useTranslations();

const formOpen = ref(false);
const promoteOpen = ref(false);

/** Mirrors the server-side guard in PlatformAdminController::revoke(). */
const canRevoke = (admin: PlatformAdmin): boolean =>
    admin.id !== props.currentUserId && props.admins.length > 1;

function revoke(admin: PlatformAdmin): void {
    if (!confirm(t('Revoke access for :name? They will lose access to the back office.', { name: admin.name }))) {
        return;
    }

    router.delete(`${ADMIN_BASE}/platform-admins/${admin.id}`, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="t('Platform Admins')">
        <div class="space-y-5">
            <PageHeader :title="t('Platform Admins')">
                <template #actions>
                    <Button variant="outline" size="sm" @click="promoteOpen = true">
                        <ArrowUpCircle class="size-3.5" :stroke-width="1.5" />
                        {{ t('Promote existing user') }}
                    </Button>
                    <Button size="sm" @click="formOpen = true">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New platform admin') }}
                    </Button>
                </template>
            </PageHeader>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Email') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Admin since') }}</th>
                                <th scope="col" class="w-24 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="admin in admins"
                                :key="admin.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3 font-medium text-foreground">{{ admin.name }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ admin.email }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ admin.created_at ? new Date(admin.created_at).toLocaleDateString() : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        v-if="canRevoke(admin)"
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive hover:bg-destructive/10"
                                        @click="revoke(admin)"
                                    >
                                        <Ban class="size-3.5" :stroke-width="1.5" />
                                        {{ t('Revoke') }}
                                    </Button>
                                </td>
                            </tr>

                            <tr v-if="admins.length === 0">
                                <td colspan="4" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No platform admins yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PlatformAdminFormPanel :open="formOpen" @close="formOpen = false" />
        <PromotePlatformAdminDialog :open="promoteOpen" :candidates="candidates" @close="promoteOpen = false" />
    </AdminLayout>
</template>
