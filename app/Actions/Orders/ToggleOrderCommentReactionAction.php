<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\OrderNote;
use App\Models\OrderNoteReaction;

/**
 * Add or remove one person's reaction to one order comment — a plain toggle
 * on the (comment, person, emoji) triple the table's own unique index
 * enforces. There is no separate "remove" endpoint: hitting the same emoji
 * twice is how a reaction is taken back, matching every reaction picker this
 * design is drawn from (GitHub, Slack).
 */
class ToggleOrderCommentReactionAction
{
    /**
     * @return bool true if the reaction was added, false if it was removed.
     */
    public function execute(OrderNote $note, string $emoji, string $userId): bool
    {
        $existing = OrderNoteReaction::query()
            ->where('order_note_id', $note->getKey())
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        OrderNoteReaction::create([
            'order_note_id' => $note->getKey(),
            'user_id' => $userId,
            'emoji' => $emoji,
        ]);

        return true;
    }
}
