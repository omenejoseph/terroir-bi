<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's emoji reaction to one order comment (Figma 376:1592).
 *
 * @property string $id
 * @property string $order_note_id
 * @property string $user_id
 * @property string $emoji
 */
class OrderNoteReaction extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /**
     * The fixed palette a reaction may be. Validated here (ToggleOrderCommentReactionRequest
     * reads it via Rule::in) rather than left open, so the comment thread can't
     * fill up with arbitrary emoji spam or non-emoji strings.
     *
     * @var list<string>
     */
    public const EMOJI = ['👍', '❤️', '🎉', '😂', '👀', '🤔'];

    protected $fillable = [
        'order_note_id',
        'user_id',
        'emoji',
    ];

    /**
     * @return BelongsTo<OrderNote, $this>
     */
    public function orderNote(): BelongsTo
    {
        return $this->belongsTo(OrderNote::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
