<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { KeyRound, Power, Search, ShieldCheck, UserCog } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminUserSummary } from '@/types/admin';
import type { Paginated } from '@/types';
import type { MenuItem } from '@/types/ui';

/**
 * Users — port of App\Filament\Resources\Users\**: a read-only directory of
 * every account. Mutations live elsewhere (Tenants' member management,
 * Platform Admins) — no create/edit/delete here.
 */
const props = defineProps<{
    users: Paginated<AdminUserSummary>;
    filters: { search: string | null };
}>();

const { t } = useTranslations();

const search = ref(props.filters.search ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/users`,
        {
            search: props.filters.search ?? undefined,
            per_page: props.users.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['users', 'filters'] },
    );
}

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    reload({ per_page: perPage, page: 1 });
}

/*
  The row's `⋯` menu — every account-level action from the Show page
  (Send password reset / Log in as / Suspend·Unsuspend), so acting on a user
  from the list doesn't require opening it first. "Log in as" is omitted for
  a platform admin the same way Show.vue hides it — the backend rejects
  impersonating another admin outright.
*/
function rowActions(row: AdminUserSummary): MenuItem[] {
    const items: MenuItem[] = [{ key: 'reset-password', label: t('Send password reset'), icon: KeyRound }];

    if (!row.is_platform_admin) {
        items.push({ key: 'impersonate', label: t('Log in as'), icon: UserCog });
    }

    items.push({
        key: 'suspension',
        label: row.is_suspended ? t('Unsuspend') : t('Suspend'),
        icon: Power,
        destructive: !row.is_suspended,
    });

    return items;
}

function onRowAction(key: string, row: AdminUserSummary): void {
    if (key === 'reset-password') return sendPasswordReset(row);
    if (key === 'impersonate') return impersonate(row);
    if (key === 'suspension') return toggleSuspension(row);
}

function sendPasswordReset(row: AdminUserSummary): void {
    if (!confirm(t('Email :name a password reset link?', { name: row.name }))) return;

    router.post(`${ADMIN_BASE}/users/${row.id}/send-password-reset`, {}, { preserveScroll: true });
}

function impersonate(row: AdminUserSummary): void {
    if (!confirm(t('Log in as :name?', { name: row.name }))) return;

    router.post(`${ADMIN_BASE}/users/${row.id}/impersonate`);
}

function toggleSuspension(row: AdminUserSummary): void {
    const suspending = !row.is_suspended;
    const message = suspending
        ? t('Suspend :name? They will be signed out immediately and cannot sign back in.', { name: row.name })
        : t('Unsuspend :name?', { name: row.name });

    if (!confirm(message)) return;

    router.patch(`${ADMIN_BASE}/users/${row.id}/suspension`, { suspended: suspending }, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="t('Users')">
        <div class="space-y-5">
            <PageHeader :title="t('Users')" />

            <div class="relative w-full max-w-[280px]">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    :stroke-width="1.5"
                />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('Filter by name or email…')"
                    :aria-label="t('Filter users')"
                    class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Email') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Platform admin') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Status') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Tenants') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Created') }}</th>
                                <th scope="col" class="w-10 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in users.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="`${ADMIN_BASE}/users/${row.id}`"
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        {{ row.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ row.email }}</td>
                                <td class="px-4 py-3">
                                    <ShieldCheck
                                        v-if="row.is_platform_admin"
                                        class="size-4 text-muted-foreground"
                                        :stroke-width="1.5"
                                    />
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="row.is_suspended" variant="outline">{{ t('Suspended') }}</Badge>
                                    <span v-else class="text-muted-foreground">{{ t('Active') }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.tenants_count }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ row.created_at ? new Date(row.created_at).toLocaleDateString() : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            :items="rowActions(row)"
                                            :label="t('Actions for :name', { name: row.name })"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="users.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No users found.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="users.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
