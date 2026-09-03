<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Pin, PinOff } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import { useAuth } from '@/composables/useAuth';
import { navItemByKey, pinnableItemsFor } from '@/lib/navigation';

/**
 * "Manage shortcuts" (Figma `143:4179`): a Pinned list with Unpin buttons, a
 * Recent list (visited pages not yet pinned) with Pin buttons and its own
 * "Clear all", and a Cancel/Save footer.
 *
 * Pin/unpin edits a local draft — nothing reaches the server until Save,
 * matching the design's own Cancel/Save pair. "Clear all" is the one action
 * that commits immediately: it discards browsing history, not the pin
 * decisions this dialog is otherwise drafting, so tying it to Save would make
 * Cancel silently keep history it just showed you erasing.
 */
const props = defineProps<{ open: boolean; pinnedKeys: string[] }>();

const emit = defineEmits<{ close: [] }>();

const { can } = useAuth();

/** The full pinnable catalog, filtered to what this member can even see. */
const catalog = computed(() => pinnableItemsFor(can));

const draft = ref<string[]>([]);
const recent = ref<string[]>([]);
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        draft.value = [...props.pinnedKeys];
        // Recent is Inertia::optional — never fetched until the dialog that
        // needs it is actually open.
        router.reload({ only: ['recentNavVisits'], onSuccess: (page) => {
            recent.value = (page.props.recentNavVisits as string[] | undefined) ?? [];
        } });
    },
    { immediate: true },
);

const pinnedItems = computed(() =>
    draft.value.map((key) => navItemByKey(key)).filter((item): item is NonNullable<typeof item> => item !== undefined),
);

/** Recently visited, not already pinned in the draft, capped to what the card has room for. */
const recentItems = computed(() =>
    recent.value
        .filter((key) => !draft.value.includes(key))
        .map((key) => navItemByKey(key))
        .filter((item): item is NonNullable<typeof item> => item !== undefined)
        .slice(0, 5),
);

function pin(key: string): void {
    if (!draft.value.includes(key)) draft.value = [...draft.value, key];
}

function unpin(key: string): void {
    draft.value = draft.value.filter((k) => k !== key);
}

function clearRecent(): void {
    recent.value = [];
    router.delete('/shortcuts/recent', { preserveScroll: true, preserveState: true });
}

function save(): void {
    saving.value = true;
    router.patch(
        '/shortcuts',
        { keys: draft.value },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                emit('close');
            },
        },
    );
}

/** Catalog items not currently pinned, offered as a fallback when nothing is "recent" yet. */
const otherPinnable = computed(() =>
    catalog.value.filter((item) => !draft.value.includes(item.key) && !recentItems.value.some((r) => r.key === item.key)),
);
</script>

<template>
    <Dialog :open="open" title="Manage shortcuts" @close="emit('close')">
        <div class="space-y-5">
            <section>
                <h3 class="text-xs font-medium text-muted-foreground">Pinned</h3>
                <ul v-if="pinnedItems.length" class="mt-2 space-y-px">
                    <li
                        v-for="item in pinnedItems"
                        :key="item.key"
                        class="flex items-center gap-2 py-1.5"
                    >
                        <component :is="item.icon" class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                        <span class="min-w-0 flex-1 truncate text-sm text-foreground">{{ item.label }}</span>
                        <button
                            type="button"
                            class="grid size-5 shrink-0 place-items-center text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="`Unpin ${item.label}`"
                            @click="unpin(item.key)"
                        >
                            <PinOff class="size-4" :stroke-width="1.5" />
                        </button>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-muted-foreground">Nothing pinned yet.</p>
            </section>

            <section>
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-xs font-medium text-muted-foreground">Recent</h3>
                    <button
                        v-if="recentItems.length"
                        type="button"
                        class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        @click="clearRecent"
                    >
                        Clear all
                    </button>
                </div>
                <ul v-if="recentItems.length" class="mt-2 space-y-px">
                    <li
                        v-for="item in recentItems"
                        :key="item.key"
                        class="flex items-center gap-2 py-1.5"
                    >
                        <component :is="item.icon" class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                        <span class="min-w-0 flex-1 truncate text-sm text-foreground">{{ item.label }}</span>
                        <button
                            type="button"
                            class="grid size-5 shrink-0 place-items-center text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="`Pin ${item.label}`"
                            @click="pin(item.key)"
                        >
                            <Pin class="size-4" :stroke-width="1.5" />
                        </button>
                    </li>
                </ul>
                <!--
                  Nothing visited yet — fall back to offering the rest of the
                  catalog, so the dialog is never just an empty "Recent"
                  section for a brand-new member with nothing pinned either.
                -->
                <ul v-else-if="otherPinnable.length" class="mt-2 space-y-px">
                    <li
                        v-for="item in otherPinnable.slice(0, 5)"
                        :key="item.key"
                        class="flex items-center gap-2 py-1.5"
                    >
                        <component :is="item.icon" class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                        <span class="min-w-0 flex-1 truncate text-sm text-foreground">{{ item.label }}</span>
                        <button
                            type="button"
                            class="grid size-5 shrink-0 place-items-center text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="`Pin ${item.label}`"
                            @click="pin(item.key)"
                        >
                            <Pin class="size-4" :stroke-width="1.5" />
                        </button>
                    </li>
                </ul>
                <p v-else class="mt-2 text-xs text-muted-foreground">Nothing visited recently.</p>
            </section>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Cancel</Button>
            <Button :disabled="saving" @click="save">Save</Button>
        </template>
    </Dialog>
</template>
