<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CategoryFormPanel from '@/components/inventory/CategoryFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import GripHandle from '@/components/ui/GripHandle.vue';
import Callout from '@/components/ui/Callout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { ProductCategory } from '@/types/inventory';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Inventory · Categories: the categories (and one level of subcategories) items are filed under, per
 * type of item. A category can be flagged as carrying vintages — that is what turns on the vintage
 * fields on the item form, and folds a wine's older vintages under its latest one on the list.
 *
 * A category can only be deleted while nothing is filed under it.
 */
const props = defineProps<{ categories: ProductCategory[] }>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const canManage = computed(() => can('inventory.manage'));

const MODULE_TABS: TabItem[] = [
    { label: t('Inventory'), href: '/inventory' },
    { label: t('Analytics'), href: '/inventory-analytics' },
    { label: t('Inventory Spend'), href: '/inventory-spend' },
    { label: t('Inventory Check'), href: '/inventory-check' },
    { label: t('Categories'), href: '/inventory/categories' },
];

const TYPES = [
    { value: 'FINISHED', label: t('Finished') },
    { value: 'SEMI_FINISHED', label: t('Semi-Finished') },
    { value: 'RAW_MATERIAL', label: t('Raw Materials') },
];

/** A local copy, so a drag lands instantly; it follows the server again whenever the props change. */
const local = ref<ProductCategory[]>(props.categories);
watch(() => props.categories, (next) => (local.value = next));

const sections = computed(() =>
    TYPES.map((type) => ({ ...type, categories: local.value.filter((c) => c.type === type.value) })),
);

/** The server refuses to delete a category that still holds items; it says so here. */
const deleteError = computed(() => (page.props.errors as Record<string, string> | undefined)?.category ?? null);

/* ---- the form panel: new category, new subcategory, or edit ----------------- */

const panelOpen = ref(false);
const editing = ref<ProductCategory | null>(null);
const parentOf = ref<ProductCategory | null>(null);
const startType = ref('FINISHED');

function openNew(type: string, parent: ProductCategory | null = null): void {
    editing.value = null;
    parentOf.value = parent;
    startType.value = type;
    panelOpen.value = true;
}

function openEdit(category: ProductCategory): void {
    editing.value = category;
    parentOf.value = null;
    panelOpen.value = true;
}

/* ---- order ------------------------------------------------------------------- */

/** Siblings are the categories of one type, or the subcategories of one parent; only those can swap places by dragging. */
const dragging = ref<{ id: string; scope: string } | null>(null);
const dropOver = ref<string | null>(null);

function startDrag(event: DragEvent, category: ProductCategory, scope: string): void {
    dragging.value = { id: category.id, scope };
    event.dataTransfer?.setData('text/plain', category.id);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function endDrag(): void {
    dragging.value = null;
    dropOver.value = null;
}

function onDragOver(event: DragEvent, category: ProductCategory, scope: string): void {
    if (dragging.value === null || dragging.value.scope !== scope) return;

    event.preventDefault();
    dropOver.value = category.id;
}

function onDrop(category: ProductCategory, siblings: ProductCategory[], scope: string, parent: ProductCategory | null): void {
    const d = dragging.value;
    endDrag();
    if (d === null || d.scope !== scope || d.id === category.id) return;

    const from = siblings.findIndex((c) => c.id === d.id);
    const to = siblings.findIndex((c) => c.id === category.id);
    if (from < 0 || to < 0) return;

    reorder(siblings, from, to, parent);
}

/** Moves one sibling to a new slot, shows it at once and saves the order for everyone. */
function reorder(siblings: ProductCategory[], from: number, to: number, parent: ProductCategory | null): void {
    const next = [...siblings];
    const [picked] = next.splice(from, 1);
    if (picked === undefined) return;
    next.splice(to, 0, picked);

    if (parent === null) {
        // Top-level categories of one type fill that type's slots in the new order.
        const ids = new Set(siblings.map((c) => c.id));
        let slot = 0;
        local.value = local.value.map((c) => (ids.has(c.id) ? next[slot++]! : c));
    } else {
        local.value = local.value.map((c) => (c.id === parent.id ? { ...c, children: next } : c));
    }

    router.post('/inventory/categories/reorder', { ids: next.map((c) => c.id) }, { preserveScroll: true });
}

function move(siblings: ProductCategory[], index: number, direction: -1 | 1, parent: ProductCategory | null = null): void {
    const target = index + direction;
    if (target < 0 || target >= siblings.length) return;

    reorder(siblings, index, target, parent);
}

/* ---- delete ------------------------------------------------------------------ */

async function remove(category: ProductCategory): Promise<void> {
    const subs = category.children?.length ?? 0;
    const ok = await confirmDialog({
        title: t('Delete :name', { name: category.name }),
        description: subs > 0 ? t('Its :count subcategories are deleted with it. No products are filed under any of them.', { count: subs }) : t('No products are filed under it.'),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/inventory/categories/${category.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Categories')" />
    <div class="space-y-5">
        <PageHeader
            :title="t('Categories')"
            :description="t('How your inventory is grouped. A category can have subcategories, and can carry vintages.')"
        />

        <Tabs :items="MODULE_TABS" :current="t('Categories')" />

        <Callout v-if="deleteError" :title="t(`Couldn't delete that category`)" tone="warning">{{ deleteError }}</Callout>

        <section v-for="section in sections" :key="section.value" class="border border-border bg-card">
            <header class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">{{ section.label }}</h2>
                <Button v-if="canManage" variant="outline" size="sm" @click="openNew(section.value)">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('Add category') }}
                </Button>
            </header>

            <ul v-if="section.categories.length" class="divide-y divide-border">
                <template v-for="(category, index) in section.categories" :key="category.id">
                    <li
                        :class="['flex flex-wrap items-center gap-3 px-4 py-3 text-xs', dropOver === category.id && 'outline outline-2 -outline-offset-2 outline-ring/60']"
                        @dragover="onDragOver($event, category, section.value)"
                        @drop.prevent="onDrop(category, section.categories, section.value, null)"
                    >
                        <GripHandle v-if="canManage" draggable :label="t('Drag to reorder')" @dragstart="startDrag($event, category, section.value)" @dragend="endDrag" />
                        <div class="min-w-0 flex-1">
                            <span class="font-medium text-foreground">{{ category.name }}</span>
                            <Badge v-if="category.has_vintage" variant="outline" class="ml-2">{{ t('Vintages') }}</Badge>
                        </div>

                        <span class="text-muted-foreground tabular-nums">{{ t(':count products', { count: category.items_count }) }}</span>

                        <div v-if="canManage" class="flex items-center gap-1.5">
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :title="t('Add subcategory')" @click="openNew(category.type, category)">
                                <Plus class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" :title="t('Move up')" :disabled="index === 0" @click="move(section.categories, index, -1)">
                                <ArrowUp class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" :title="t('Move down')" :disabled="index === section.categories.length - 1" @click="move(section.categories, index, 1)">
                                <ArrowDown class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :title="t('Edit')" @click="openEdit(category)">
                                <Pencil class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button
                                type="button"
                                class="p-1 text-muted-foreground hover:text-destructive disabled:opacity-30 disabled:hover:text-muted-foreground"
                                :title="category.items_count > 0 ? t('Move its products to another category first') : t('Delete')"
                                :disabled="category.items_count > 0"
                                @click="remove(category)"
                            >
                                <Trash2 class="size-3.5" :stroke-width="1.5" />
                            </button>
                        </div>
                    </li>

                    <li
                        v-for="(sub, subIndex) in category.children ?? []"
                        :key="sub.id"
                        :class="['flex flex-wrap items-center gap-3 bg-muted/20 py-2.5 pr-4 pl-9 text-xs', dropOver === sub.id && 'outline outline-2 -outline-offset-2 outline-ring/60']"
                        @dragover="onDragOver($event, sub, category.id)"
                        @drop.prevent="onDrop(sub, category.children ?? [], category.id, category)"
                    >
                        <GripHandle v-if="canManage" draggable :label="t('Drag to reorder')" @dragstart="startDrag($event, sub, category.id)" @dragend="endDrag" />
                        <div class="min-w-0 flex-1">
                            <span class="text-foreground">{{ sub.name }}</span>
                            <Badge v-if="sub.has_vintage" variant="outline" class="ml-2">{{ t('Vintages') }}</Badge>
                        </div>

                        <span class="text-muted-foreground tabular-nums">{{ t(':count products', { count: sub.items_count }) }}</span>

                        <div v-if="canManage" class="flex items-center gap-1.5">
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" :title="t('Move up')" :disabled="subIndex === 0" @click="move(category.children ?? [], subIndex, -1, category)">
                                <ArrowUp class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" :title="t('Move down')" :disabled="subIndex === (category.children?.length ?? 0) - 1" @click="move(category.children ?? [], subIndex, 1, category)">
                                <ArrowDown class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :title="t('Edit')" @click="openEdit(sub)">
                                <Pencil class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button
                                type="button"
                                class="p-1 text-muted-foreground hover:text-destructive disabled:opacity-30 disabled:hover:text-muted-foreground"
                                :title="sub.items_count > 0 ? t('Move its products to another category first') : t('Delete')"
                                :disabled="sub.items_count > 0"
                                @click="remove(sub)"
                            >
                                <Trash2 class="size-3.5" :stroke-width="1.5" />
                            </button>
                        </div>
                    </li>
                </template>
            </ul>
            <p v-else class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('No categories yet.') }}</p>
        </section>
    </div>

    <CategoryFormPanel :open="panelOpen" :category="editing" :parent="parentOf" :default-type="startType" @close="panelOpen = false" />
</template>
