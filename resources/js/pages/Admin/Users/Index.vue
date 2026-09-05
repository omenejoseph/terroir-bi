<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Search, ShieldCheck } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminUserSummary } from '@/types/admin';
import type { Paginated } from '@/types';

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
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Tenants') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Created') }}</th>
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
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.tenants_count }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ row.created_at ? new Date(row.created_at).toLocaleDateString() : '—' }}
                                </td>
                            </tr>

                            <tr v-if="users.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
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
