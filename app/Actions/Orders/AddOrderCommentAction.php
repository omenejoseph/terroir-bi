<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderNote;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Add a threaded comment to an order and notify: @mentioned users get a MENTION,
 * other followers (creator + prior commenters) get a REPLY.
 */
class AddOrderCommentAction
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  list<string>  $mentions
     */
    public function execute(Order $order, string $content, array $mentions, string $authorId): OrderNote
    {
        $note = DB::transaction(fn (): OrderNote => $order->orderNotes()->create([
            'content' => $content,
            'author_id' => $authorId,
        ]));

        $this->notifier->orderComment($order, $content, $mentions, $authorId);

        return $note;
    }
}
