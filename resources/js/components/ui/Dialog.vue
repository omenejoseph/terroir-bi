<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';

/**
 * The centred modal the design uses for Manage Shortcuts (Figma `143:4179`,
 * "DialogPopup") — distinct from SidePanel, which is a full-height drawer for
 * records and forms. This is a small, self-contained decision surface: a
 * backdrop, a title with a close control, a body, and an optional footer.
 *
 * Geometry from that node: 448px wide, centred, with the same focus-trap and
 * Escape handling as SidePanel — copied rather than shared, since the two
 * differ enough (centred vs. edge-anchored, no `translate-x` transition) that
 * factoring them together would cost more than the duplication.
 */
const props = withDefaults(defineProps<{ open: boolean; title: string; describedBy?: string }>(), {
    open: false,
});

const emit = defineEmits<{ close: [] }>();

const dialog = ref<HTMLElement | null>(null);

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');

        return;
    }

    if (event.key !== 'Tab' || dialog.value === null) return;

    const focusable = dialog.value.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    );

    if (focusable.length === 0) return;

    const first = focusable[0]!;
    const last = focusable[focusable.length - 1]!;

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

let restoreTo: HTMLElement | null = null;

watch(
    () => props.open,
    async (open) => {
        if (typeof document === 'undefined') return;

        if (open) {
            restoreTo = document.activeElement as HTMLElement | null;
            document.addEventListener('keydown', onKeydown);
            document.body.style.overflow = 'hidden';
            await Promise.resolve();
            const body = dialog.value?.querySelector<HTMLElement>('[data-dialog-body]');
            const first = body?.querySelector<HTMLElement>('input, select, textarea, button');
            (first ?? dialog.value)?.focus();
        } else {
            document.removeEventListener('keydown', onKeydown);
            document.body.style.overflow = '';
            restoreTo?.focus();
            restoreTo = null;
        }
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 bg-black/40" aria-hidden="true" @click="emit('close')" />
        </Transition>

        <Transition
            enter-active-class="transition-all duration-150 ease-out"
            leave-active-class="transition-all duration-100 ease-in"
            enter-from-class="opacity-0 scale-95"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 grid place-items-center p-4"
                @click.self="emit('close')"
            >
                <div
                    ref="dialog"
                    class="flex max-h-[calc(100vh-2rem)] w-full max-w-[28rem] flex-col bg-card shadow-2xl focus:outline-none"
                    tabindex="-1"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="title"
                    :aria-describedby="describedBy"
                >
                    <div class="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-border px-4">
                        <h2 class="truncate text-sm font-semibold text-foreground">{{ title }}</h2>
                        <button
                            type="button"
                            class="-mr-1.5 p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            aria-label="Close"
                            @click="emit('close')"
                        >
                            <X class="size-4" :stroke-width="1.5" />
                        </button>
                    </div>

                    <div data-dialog-body class="min-h-0 flex-1 overflow-y-auto p-4">
                        <slot />
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="flex shrink-0 items-center justify-end gap-2 border-t border-border p-4"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
