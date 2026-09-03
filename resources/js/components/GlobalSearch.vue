<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';

import Kbd from '@/components/ui/Kbd.vue';
import { usePopover } from '@/composables/usePopover';
import { useTranslations } from '@/composables/useTranslations';
import type { SearchResult, SearchResults } from '@/types/search';

const { t } = useTranslations();

/**
 * The header's global search (Figma 389:1679): searches orders, customers and
 * inventory (by name or SKU) and navigates to the match on selection.
 *
 * Each category is gated server-side by capability and tenant module (see
 * Web\SearchController) and simply omitted rather than erroring, so a member
 * without inventory.view just never sees an Inventory group — this dropdown
 * has no gating logic of its own to keep in sync with that.
 *
 * Keyboard nav (Arrow keys, Enter, the `data-index` + scrollIntoView cursor)
 * and the popover dismiss behaviour follow ui/Combobox.vue, the app's other
 * type-to-filter control — this one differs by fetching its own options from
 * the server instead of filtering a fixed list.
 */
const EMPTY: SearchResults = { orders: [], customers: [], inventory: [] };

const anchor = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);
const { open, show, close } = usePopover(anchor);

const query = ref('');
const results = ref<SearchResults>(EMPTY);
const loading = ref(false);
const activeIndex = ref(0);
const listId = useId();

/** Same three categories, in render order, joined for O(1) keyboard-nav lookups. */
const flat = computed<SearchResult[]>(() => [...results.value.orders, ...results.value.customers, ...results.value.inventory]);

/** Grouped for the dropdown, with each item's index into `flat` attached. */
const groups = computed(() => {
    let index = 0;

    return (
        [
            { label: t('Orders'), items: results.value.orders },
            { label: t('Customers'), items: results.value.customers },
            { label: t('Inventory'), items: results.value.inventory },
        ] satisfies { label: string; items: SearchResult[] }[]
    )
        .filter((group) => group.items.length > 0)
        .map((group) => ({
            label: group.label,
            items: group.items.map((item) => ({ item, index: index++ })),
        }));
});

const showDropdown = computed(() => open.value && query.value.trim().length >= 2);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

watch(query, (term) => {
    clearTimeout(timer);
    activeIndex.value = 0;

    if (term.trim().length < 2) {
        controller?.abort();
        loading.value = false;
        results.value = EMPTY;

        return;
    }

    timer = setTimeout(() => void search(term.trim()), 300);
});

async function search(term: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;

    try {
        const response = await fetch(`/search?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        results.value = response.ok ? ((await response.json()) as SearchResults) : EMPTY;
    } catch (error) {
        // An aborted request just means a newer keystroke superseded this one —
        // its own response will land and set `results`; anything else is a
        // real failure, so it clears the dropdown rather than showing stale data.
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
            results.value = EMPTY;
        }
    } finally {
        loading.value = false;
    }
}

function go(result: SearchResult | undefined): void {
    if (result === undefined) return;

    close({ restoreFocus: false });
    query.value = '';
    router.visit(result.url);
}

function move(delta: number): void {
    if (flat.value.length === 0) return;

    activeIndex.value = (activeIndex.value + delta + flat.value.length) % flat.value.length;

    nextTick(() => {
        list.value?.querySelector(`[data-index="${activeIndex.value}"]`)?.scrollIntoView({ block: 'nearest' });
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (!showDropdown.value) return;

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Enter':
            event.preventDefault();
            go(flat.value[activeIndex.value]);
            break;
    }
}

/** ⌘K / Ctrl+K focuses the field from anywhere in the app, per the Kbd hint. */
function onGlobalKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        show();
        input.value?.focus();
    }
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKeydown));
</script>

<template>
    <div ref="anchor" class="relative hidden w-72 sm:block">
        <Search
            class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
            :stroke-width="1.5"
        />
        <input
            :id="`${listId}-input`"
            ref="input"
            v-model="query"
            type="search"
            :placeholder="t('Search orders, SKUs, partners…')"
            :aria-label="t('Search')"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="showDropdown"
            :aria-controls="listId"
            :aria-activedescendant="showDropdown && flat.length > 0 ? `${listId}-${activeIndex}` : undefined"
            class="h-8 w-full border border-input bg-muted pr-10 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @focus="show"
            @keydown="onKeydown"
        />
        <Kbd v-if="query === ''" keys="⌘K" class="absolute top-1/2 right-2 -translate-y-1/2" />

        <div
            v-if="showDropdown"
            :id="listId"
            ref="list"
            role="listbox"
            class="absolute top-10 right-0 left-0 z-30 max-h-96 overflow-y-auto border border-border bg-card shadow-lg"
        >
            <p v-if="loading" class="px-3 py-3 text-xs text-muted-foreground">{{ t('Searching…') }}</p>

            <template v-else-if="flat.length > 0">
                <div v-for="group in groups" :key="group.label">
                    <p class="px-3 pt-2 pb-1 text-[11px] font-medium text-muted-foreground">{{ group.label }}</p>
                    <button
                        v-for="entry in group.items"
                        :id="`${listId}-${entry.index}`"
                        :key="entry.item.id"
                        :data-index="entry.index"
                        type="button"
                        role="option"
                        :aria-selected="entry.index === activeIndex"
                        class="flex h-8 w-full items-center gap-2 px-3 text-left text-xs transition-colors"
                        :class="entry.index === activeIndex ? 'bg-muted' : 'hover:bg-muted/60'"
                        @click="go(entry.item)"
                        @mousemove="activeIndex = entry.index"
                    >
                        <span class="min-w-0 flex-1 truncate">
                            {{ entry.item.title }}
                            <span v-if="entry.item.subtitle" class="ml-2 text-muted-foreground">{{ entry.item.subtitle }}</span>
                        </span>
                    </button>
                </div>
            </template>

            <p v-else class="px-3 py-3 text-xs text-muted-foreground">{{ t('Nothing matches ":query".', { query }) }}</p>
        </div>
    </div>
</template>
