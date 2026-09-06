<script setup lang="ts">
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { PencilLine, Plus, RotateCcw, Search } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import TranslationOverrideFormPanel from '@/components/admin/TranslationOverrideFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, TranslationCatalogRow, TranslationOverride } from '@/types/admin';
import type { Paginated } from '@/types';
import type { MenuItem } from '@/types/ui';

/**
 * Translation Overrides — browses every bundled UI string (the same JSON
 * source-string catalog TranslationService::all() merges overrides into,
 * one locale at a time) so any of them can be overridden without knowing its
 * exact key up front, rather than only listing overrides already made.
 * "New override" still exists for a key outside that catalog.
 */
const props = defineProps<{
    catalog: Paginated<TranslationCatalogRow>;
    filters: { search: string | null; locale: string };
    localeOptions: AdminOption[];
}>();

const { t } = useTranslations();

const search = ref(props.filters.search ?? '');
const formOpen = ref(false);
const editing = ref<TranslationOverride | null>(null);
const prefill = ref<{ locale: string; key: string; value: string } | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined, page: 1 }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/translation-overrides`,
        {
            search: props.filters.search ?? undefined,
            locale: props.filters.locale,
            per_page: props.catalog.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['catalog', 'filters'] },
    );
}

function selectLocale(locale: string): void {
    reload({ locale, page: 1 });
}

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    reload({ per_page: perPage, page: 1 });
}

function createBlank(): void {
    editing.value = null;
    prefill.value = null;
    formOpen.value = true;
}

/** The catalog row's "Override" — pre-filled, but still a new row (POST) until saved. */
function override(row: TranslationCatalogRow): void {
    editing.value = null;
    prefill.value = { locale: props.filters.locale, key: row.key, value: row.value };
    formOpen.value = true;
}

/** An already-overridden row's "Edit override" — the real record (PATCH). */
function editOverride(row: TranslationCatalogRow): void {
    if (row.override_id === null) return;

    editing.value = { id: row.override_id, locale: props.filters.locale, key: row.key, value: row.value };
    prefill.value = null;
    formOpen.value = true;
}

async function resetToBundled(row: TranslationCatalogRow): Promise<void> {
    if (row.override_id === null) return;

    const ok = await confirmDialog({
        title: t('Reset to bundled'),
        description: t('Reset ":key" to the bundled string?', { key: row.key }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`${ADMIN_BASE}/translation-overrides/${row.override_id}`, { preserveScroll: true });
}

function rowActions(row: TranslationCatalogRow): MenuItem[] {
    return row.is_overridden
        ? [
              { key: 'edit', label: t('Edit override'), icon: PencilLine },
              { key: 'reset', label: t('Reset to bundled'), icon: RotateCcw, destructive: true },
          ]
        : [{ key: 'override', label: t('Override'), icon: PencilLine }];
}

function onRowAction(key: string, row: TranslationCatalogRow): void {
    if (key === 'override') return override(row);
    if (key === 'edit') return editOverride(row);
    if (key === 'reset') void resetToBundled(row);
}
</script>

<template>
    <AdminLayout :title="t('Translation Overrides')">
        <div class="space-y-5">
            <PageHeader :title="t('Translation Overrides')">
                <template #actions>
                    <Button variant="outline" size="sm" @click="createBlank">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New override') }}
                    </Button>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex border border-border">
                    <button
                        v-for="option in localeOptions"
                        :key="option.value"
                        type="button"
                        class="px-3 py-1.5 text-xs transition-colors"
                        :class="
                            option.value === filters.locale
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-card text-foreground hover:bg-muted'
                        "
                        @click="selectLocale(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div class="relative w-full max-w-[280px]">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                        :stroke-width="1.5"
                    />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('Filter by key or value…')"
                        :aria-label="t('Filter translations')"
                        class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Key') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Current value') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Status') }}</th>
                                <th scope="col" class="w-16 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in catalog.data"
                                :key="row.key"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3 font-medium text-foreground">
                                    {{ row.key.length > 80 ? `${row.key.slice(0, 80)}…` : row.key }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ row.value.length > 80 ? `${row.value.slice(0, 80)}…` : row.value }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="row.is_overridden" variant="outline">{{ t('Overridden') }}</Badge>
                                    <span v-else class="text-muted-foreground">{{ t('Bundled') }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            :items="rowActions(row)"
                                            :label="t('Actions for :key', { key: row.key })"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="catalog.data.length === 0">
                                <td colspan="4" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No translations match this filter.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="catalog.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>

        <TranslationOverrideFormPanel
            :open="formOpen"
            :override="editing"
            :prefill="prefill"
            @close="formOpen = false"
        />
    </AdminLayout>
</template>
