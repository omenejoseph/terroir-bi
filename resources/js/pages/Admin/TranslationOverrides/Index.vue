<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { PencilLine, Plus, Search, Trash2 } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import TranslationOverrideFormPanel from '@/components/admin/TranslationOverrideFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { TranslationOverride } from '@/types/admin';
import type { Paginated } from '@/types';
import type { MenuItem } from '@/types/ui';

/**
 * Translation Overrides — port of App\Filament\Resources\TranslationOverrides\**.
 * Plain list + create/edit via SidePanel, no view page (matches the Filament
 * resource exactly).
 */
const props = defineProps<{
    overrides: Paginated<TranslationOverride>;
    filters: { search: string | null };
}>();

const { t } = useTranslations();

const search = ref(props.filters.search ?? '');
const formOpen = ref(false);
const editing = ref<TranslationOverride | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/translation-overrides`,
        {
            search: props.filters.search ?? undefined,
            per_page: props.overrides.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['overrides', 'filters'] },
    );
}

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    reload({ per_page: perPage, page: 1 });
}

function create(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(override: TranslationOverride): void {
    editing.value = override;
    formOpen.value = true;
}

const rowActions: MenuItem[] = [
    { key: 'edit', label: t('Edit'), icon: PencilLine },
    { key: 'delete', label: t('Delete'), icon: Trash2, destructive: true },
];

function onRowAction(key: string, override: TranslationOverride): void {
    if (key === 'edit') {
        edit(override);

        return;
    }

    if (key === 'delete') destroy(override);
}

function destroy(override: TranslationOverride): void {
    if (!confirm(t('Delete this override? The bundled string will show again.'))) return;

    router.delete(`${ADMIN_BASE}/translation-overrides/${override.id}`, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="t('Translation Overrides')">
        <div class="space-y-5">
            <PageHeader :title="t('Translation Overrides')">
                <template #actions>
                    <Button size="sm" @click="create">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New override') }}
                    </Button>
                </template>
            </PageHeader>

            <div class="relative w-full max-w-[280px]">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    :stroke-width="1.5"
                />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('Filter by key or value…')"
                    :aria-label="t('Filter translation overrides')"
                    class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Locale') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Key') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Value') }}</th>
                                <th scope="col" class="w-16 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in overrides.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3"><Badge>{{ row.locale }}</Badge></td>
                                <td class="px-4 py-3 font-medium text-foreground">{{ row.key }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ row.value.length > 80 ? `${row.value.slice(0, 80)}…` : row.value }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            :items="rowActions"
                                            :label="t('Actions for :key', { key: row.key })"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="overrides.data.length === 0">
                                <td colspan="4" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No translation overrides yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="overrides.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>

        <TranslationOverrideFormPanel :open="formOpen" :override="editing" @close="formOpen = false" />
    </AdminLayout>
</template>
