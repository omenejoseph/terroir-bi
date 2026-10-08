<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Check, Pencil, Plus, Trash2, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import InputError from '@/components/ui/InputError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Switch from '@/components/ui/Switch.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { CustomerCategoryRow } from '@/types/customers';
import type { TabItem } from '@/types/ui';

/**
 * Customer categories: the labels an organisation puts on its customers ("Restaurant",
 * "Hotel", …) and the order it wants them offered in. Separate from a customer's sales
 * channel (Wholesale, Retail, …), which is fixed and drives the dashboard's revenue split.
 *
 * Deleting a category never deletes customers: they keep existing, without a label.
 */
const props = defineProps<{ categories: CustomerCategoryRow[] }>();

const { can } = useAuth();
const { t } = useTranslations();

const canManage = computed(() => can('customers.manage'));

const MODULE_TABS = computed<TabItem[]>(() => [
    { label: t('Customers'), href: '/customers' },
    { label: t('Analytics'), href: '/customers-analytics' },
    { label: t('Categories'), href: '/customers/categories' },
]);

const addForm = useForm({ name: '' });

function add(): void {
    if (addForm.name.trim() === '') return;

    addForm.post('/customers/categories', {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

const editingId = ref<string | null>(null);
const editForm = useForm({ name: '' });

function startEdit(category: CustomerCategoryRow): void {
    editingId.value = category.id;
    editForm.name = category.name;
    editForm.clearErrors();
}

function saveEdit(category: CustomerCategoryRow): void {
    editForm.patch(`/customers/categories/${category.id}`, {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
}

function setActive(category: CustomerCategoryRow, value: boolean): void {
    router.patch(`/customers/categories/${category.id}`, { is_active: value }, { preserveScroll: true });
}

function move(index: number, direction: -1 | 1): void {
    const ids = props.categories.map((c) => c.id);
    const target = index + direction;
    if (target < 0 || target >= ids.length) return;

    const [moved] = ids.splice(index, 1);
    if (moved === undefined) return;
    ids.splice(target, 0, moved);
    router.post('/customers/categories/reorder', { ids }, { preserveScroll: true });
}

async function remove(category: CustomerCategoryRow): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete :name', { name: category.name }),
        description:
            category.customers_count > 0
                ? t(':count customer(s) will keep their record but lose this category.', { count: category.customers_count })
                : t('No customers use this category.'),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/customers/categories/${category.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t('Customer categories')">
        <div class="space-y-5">
            <PageHeader
                :title="t('Customer categories')"
                :description="t('Labels for your customers, such as Restaurant or Hotel. Customers can be filtered by them.')"
            />

            <Tabs :items="MODULE_TABS" :current="t('Categories')" />

            <form v-if="canManage" class="flex flex-wrap items-start gap-2" @submit.prevent="add">
                <div class="w-full max-w-xs">
                    <Input
                        v-model="addForm.name"
                        :placeholder="t('New category name')"
                        :aria-label="t('New category name')"
                        :invalid="!!addForm.errors.name"
                    />
                    <InputError :message="addForm.errors.name" />
                </div>
                <Button type="submit" size="sm" :disabled="addForm.processing || addForm.name.trim() === ''">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('Add category') }}
                </Button>
            </form>

            <div class="border border-border bg-card">
                <ul v-if="categories.length" class="divide-y divide-border">
                    <li v-for="(category, index) in categories" :key="category.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-xs">
                        <div class="min-w-0 flex-1">
                            <form v-if="editingId === category.id" class="flex items-start gap-2" @submit.prevent="saveEdit(category)">
                                <div class="w-full max-w-xs">
                                    <Input v-model="editForm.name" :aria-label="t('Category name')" :invalid="!!editForm.errors.name" />
                                    <InputError :message="editForm.errors.name" />
                                </div>
                                <Button type="submit" size="sm" :disabled="editForm.processing">
                                    <Check class="size-3.5" :stroke-width="1.5" />
                                    {{ t('Save') }}
                                </Button>
                                <Button type="button" variant="outline" size="sm" @click="editingId = null">
                                    <X class="size-3.5" :stroke-width="1.5" />
                                    {{ t('Cancel') }}
                                </Button>
                            </form>
                            <template v-else>
                                <span class="font-medium text-foreground" :class="{ 'text-muted-foreground line-through': !category.is_active }">
                                    {{ category.name }}
                                </span>
                                <Badge v-if="!category.is_active" variant="warning" class="ml-2">{{ t('Retired') }}</Badge>
                            </template>
                        </div>

                        <span class="text-muted-foreground tabular-nums">{{ t(':count customer(s)', { count: category.customers_count }) }}</span>

                        <div v-if="canManage" class="flex items-center gap-1.5">
                            <Switch
                                :model-value="category.is_active"
                                :label="t('Offer :name to new customers', { name: category.name })"
                                @update:model-value="setActive(category, $event)"
                            />
                            <button
                                type="button"
                                class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                :title="t('Move up')"
                                :disabled="index === 0"
                                @click="move(index, -1)"
                            >
                                <ArrowUp class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button
                                type="button"
                                class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                :title="t('Move down')"
                                :disabled="index === categories.length - 1"
                                @click="move(index, 1)"
                            >
                                <ArrowDown class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :title="t('Rename')" @click="startEdit(category)">
                                <Pencil class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :title="t('Delete')" @click="remove(category)">
                                <Trash2 class="size-3.5" :stroke-width="1.5" />
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-12 text-center text-xs text-muted-foreground">{{ t('No categories yet.') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
