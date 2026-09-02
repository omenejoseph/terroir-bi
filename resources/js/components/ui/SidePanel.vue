<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';

/**
 * The 480px drawer the design uses for every create/edit/view form
 * (Figma `317:468`).
 *
 * Geometry from that node: 480px wide, a 64px header with the title at 24px in
 * and the close control at the right edge, a 1px rule, a scrolling body padded
 * 24px, and a footer whose actions are 36px tall and 8px apart.
 *
 * This is deliberately NOT a route. Fourteen screens in the design are drawers
 * over the page behind them; giving each a URL would contradict the design and
 * lose the caller's list state.
 */
const props = withDefaults(
    defineProps<{ open: boolean; title: string; subtitle?: string; describedBy?: string }>(),
    { open: false },
);

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');

        return;
    }

    // Keep Tab inside the panel while it is open, so focus cannot wander into
    // the inert page behind it.
    if (event.key !== 'Tab' || panel.value === null) return;

    const focusable = panel.value.querySelectorAll<HTMLElement>(
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

/** Restore focus to whatever opened the panel, so the keyboard path is not lost. */
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
            /*
              Prefer the first control in the BODY. A read drawer's first
              focusable element is the header's overflow menu, and landing
              there says "you are on a menu" when the reader opened a record.
              A form drawer still gets its first field. If the body has no
              control, focus the panel itself so the Escape/Tab trap still owns
              the keyboard.
            */
            const body = panel.value?.querySelector<HTMLElement>('[data-panel-body]');
            const first = body?.querySelector<HTMLElement>('input, select, textarea, button');
            (first ?? panel.value)?.focus();
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
            enter-active-class="transition-transform duration-200 ease-out"
            leave-active-class="transition-transform duration-150 ease-in"
            enter-from-class="translate-x-full"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="open"
                ref="panel"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[480px] flex-col bg-card shadow-2xl focus:outline-none"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                :aria-describedby="describedBy"
            >
                <!--
                  Header: 64px with a 20px title (317:468), or 78px with an 18px
                  title over a 12px subtitle when the panel names a record rather
                  than an action (376:1592, "Restoran Mediteran / #VT-20260035 ·
                  20 Jun 2026"). Close sits at the right edge in both.
                -->
                <div
                    class="flex shrink-0 items-center justify-between gap-3 border-b border-border px-6"
                    :class="subtitle ? 'h-[78px]' : 'h-16'"
                >
                    <div class="min-w-0">
                        <h2
                            class="truncate font-semibold text-foreground"
                            :class="subtitle ? 'text-lg' : 'text-xl'"
                        >
                            {{ title }}
                        </h2>
                        <p v-if="subtitle" class="truncate text-xs text-muted-foreground">{{ subtitle }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <slot name="header-actions" />
                        <button
                            type="button"
                            class="-mr-2 p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            aria-label="Close"
                            @click="emit('close')"
                        >
                            <X class="size-5" :stroke-width="1.5" />
                        </button>
                    </div>
                </div>

                <!-- Optional band between the header and the body: the Order —
                     View drawer puts its identity chips and provenance line here. -->
                <div v-if="$slots.meta" class="shrink-0 border-b border-border px-6 py-3">
                    <slot name="meta" />
                </div>

                <div data-panel-body class="min-h-0 flex-1 overflow-y-auto p-6">
                    <slot />
                </div>

                <div
                    v-if="$slots.footer"
                    class="flex shrink-0 items-center justify-end gap-2 border-t border-border p-6"
                >
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
