<script setup lang="ts">
import { useId } from 'vue';
import { ChevronDown, ChevronsLeft, ChevronLeft, ChevronRight, ChevronsRight } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';

/**
 * The pager every list table ends in (Figma `230:3000`, "PaginationBar"): a
 * "Rows per page" select, a "Page X of Y" label and four 28px nav buttons —
 * first / previous / next / last, each a 14px icon.
 *
 * One component because every list page (Customers, Orders, Inventory, and the
 * Order History tab on a customer) was re-solving page-clamping and building
 * its own subset of these four buttons, and none of them had gained the
 * rows-per-page control at all — the design has always drawn one.
 *
 * The left-hand content (a selection count, a "Showing X–Y of Z" summary, or
 * nothing) differs per page, so it stays a slot rather than a prop this
 * component would have to guess the wording of.
 */
const props = withDefaults(
    defineProps<{
        meta: { current_page: number; last_page: number; per_page: number; total: number };
        perPageOptions?: number[];
    }>(),
    // Mirrors App\Support\PerPage::OPTIONS — keep the two in sync.
    { perPageOptions: () => [10, 25, 50, 100] },
);

const emit = defineEmits<{ 'update:page': [page: number]; 'update:per-page': [perPage: number] }>();

const selectId = useId();

/** At least 1, even for an empty result — "Page 1 of 0" reads as broken. */
const lastPage = () => Math.max(1, props.meta.last_page);

function go(page: number): void {
    const clamped = Math.min(Math.max(1, page), lastPage());

    if (clamped !== props.meta.current_page) emit('update:page', clamped);
}

function changePerPage(event: Event): void {
    emit('update:per-page', Number((event.target as HTMLSelectElement).value));
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
            <label :for="selectId" class="text-xs text-muted-foreground whitespace-nowrap">Rows per page</label>
            <div class="relative">
                <select
                    :id="selectId"
                    :value="meta.per_page"
                    class="h-8 w-[60px] appearance-none border border-input bg-card py-1 pr-6 pl-2 text-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @change="changePerPage"
                >
                    <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
                <ChevronDown
                    class="pointer-events-none absolute top-1/2 right-1.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    :stroke-width="1.5"
                />
            </div>
        </div>

        <p class="text-xs text-muted-foreground whitespace-nowrap">Page {{ meta.current_page }} of {{ lastPage() }}</p>

        <div class="flex items-center gap-1">
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.current_page <= 1"
                aria-label="First page"
                @click="go(1)"
            >
                <ChevronsLeft class="size-3.5" :stroke-width="1.5" />
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.current_page <= 1"
                aria-label="Previous page"
                @click="go(meta.current_page - 1)"
            >
                <ChevronLeft class="size-3.5" :stroke-width="1.5" />
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.current_page >= lastPage()"
                aria-label="Next page"
                @click="go(meta.current_page + 1)"
            >
                <ChevronRight class="size-3.5" :stroke-width="1.5" />
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="meta.current_page >= lastPage()"
                aria-label="Last page"
                @click="go(lastPage())"
            >
                <ChevronsRight class="size-3.5" :stroke-width="1.5" />
            </Button>
        </div>
    </div>
</template>
