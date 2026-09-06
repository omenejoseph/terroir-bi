<script setup lang="ts">
import { useTranslations } from '@/composables/useTranslations';
import type { OrderCommentReaction } from '@/types/orders';

/**
 * The Order — View drawer's Comments section (Figma 376:1592): a fixed
 * emoji palette under each comment, mirroring App\Models\OrderNoteReaction
 * ::EMOJI exactly — reactions are validated server-side against that same
 * list, so this is not a free-text picker.
 *
 * Always renders all six, dim when unused — Slack/GitHub instead hide the
 * unused ones behind a hover-revealed "add reaction" trigger, but that needs
 * a floating picker, and this drawer's comment list scrolls (see the
 * "picker dropdowns must portal" lesson elsewhere in this app) — showing the
 * whole palette up front sidesteps that entirely, at the cost of a few extra
 * dim buttons per comment.
 */
const PALETTE = ['👍', '❤️', '🎉', '😂', '👀', '🤔'];

const props = defineProps<{ reactions: OrderCommentReaction[]; currentUserId: string | null }>();
const emit = defineEmits<{ toggle: [emoji: string] }>();
const { t } = useTranslations();

function group(emoji: string): OrderCommentReaction | undefined {
    return props.reactions.find((r) => r.emoji === emoji);
}

function count(emoji: string): number {
    return group(emoji)?.count ?? 0;
}

function mine(emoji: string): boolean {
    return props.currentUserId !== null && (group(emoji)?.user_ids.includes(props.currentUserId) ?? false);
}
</script>

<template>
    <div class="mt-1.5 flex flex-wrap items-center gap-1">
        <button
            v-for="emoji in PALETTE"
            :key="emoji"
            type="button"
            class="inline-flex h-6 items-center gap-1 rounded-full border px-1.5 text-xs transition-colors"
            :class="
                mine(emoji)
                    ? 'border-primary bg-primary/10 text-foreground'
                    : count(emoji) > 0
                      ? 'border-border bg-muted/60 text-foreground hover:border-foreground/40'
                      : 'border-transparent text-muted-foreground/50 hover:border-border hover:text-muted-foreground'
            "
            :aria-pressed="mine(emoji)"
            :aria-label="t('React with :emoji', { emoji })"
            @click="emit('toggle', emoji)"
        >
            <span aria-hidden="true">{{ emoji }}</span>
            <span v-if="count(emoji) > 0" class="tabular-nums">{{ count(emoji) }}</span>
        </button>
    </div>
</template>
