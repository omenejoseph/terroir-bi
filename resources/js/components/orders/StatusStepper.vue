<script setup lang="ts">
import { computed } from 'vue';

import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';

/**
 * The order's status as a tappable stepper (Figma 376:1592, "Status · Tap to
 * update"): a 24px dot per step joined by a hairline, with the reached steps
 * filled and ticked and the remaining ones outlined and numbered.
 *
 * The steps ARE the transitions. `UpdateOrderStatusAction` is what enforces
 * which moves are legal and what they do to stock, so this control offers every
 * step and lets the server refuse — it never encodes the rules a second time.
 */
export interface Step {
    key: string;
    label: string;
}

const props = defineProps<{
    steps: Step[];
    current: string;
    /** False for a viewer without orders.manage: the stepper reads, not writes. */
    editable: boolean;
}>();

const emit = defineEmits<{ select: [key: string] }>();

const { t } = useTranslations();

const currentIndex = computed(() => props.steps.findIndex((step) => step.key === props.current));
</script>

<template>
    <div class="flex items-start">
        <div
            v-for="(step, i) in steps"
            :key="step.key"
            class="flex flex-col gap-2"
            :class="i === steps.length - 1 ? 'shrink-0' : 'min-w-0 flex-1'"
        >
            <div class="flex items-center">
                <component
                    :is="editable ? 'button' : 'div'"
                    :type="editable ? 'button' : undefined"
                    :aria-current="i === currentIndex ? 'step' : undefined"
                    :aria-label="editable ? t('Move to :step', { step: step.label }) : step.label"
                    :class="
                        cn(
                            'flex size-6 shrink-0 items-center justify-center border text-2xs font-semibold tabular-nums',
                            i < currentIndex && 'border-primary bg-primary text-primary-foreground',
                            // The step you are on is outlined, not filled: it is
                            // where the order sits, not somewhere it has been.
                            i === currentIndex && 'border-primary bg-card text-foreground',
                            i > currentIndex && 'border-border bg-card text-muted-foreground',
                            editable && 'transition-opacity hover:opacity-70',
                        )
                    "
                    @click="editable ? emit('select', step.key) : undefined"
                >
                    <span v-if="i < currentIndex" aria-hidden="true">✓</span>
                    <span v-else>{{ i + 1 }}</span>
                </component>

                <span
                    v-if="i < steps.length - 1"
                    class="h-px min-w-0 flex-1"
                    :class="i < currentIndex ? 'bg-primary' : 'bg-border'"
                    aria-hidden="true"
                />
            </div>

            <span
                class="truncate pr-3 text-xs"
                :class="i === currentIndex ? 'font-semibold text-foreground' : 'text-muted-foreground'"
            >
                {{ step.label }}
            </span>
        </div>
    </div>
</template>
