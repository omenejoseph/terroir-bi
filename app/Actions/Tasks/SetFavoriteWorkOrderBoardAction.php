<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sets (or clears) a member's one favourite board — the header's "Favorited"
 * button jumps straight to it. Delete-then-insert in a transaction, the same
 * style SetPinnedShortcutsAction uses, simplified to a single row: the
 * composite primary key on (tenant_id, user_id) means there can only ever be
 * one, so setting a new favourite always replaces whichever one existed.
 */
class SetFavoriteWorkOrderBoardAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function execute(string $userId, ?string $boardId): void
    {
        DB::transaction(function () use ($userId, $boardId): void {
            DB::table('user_work_order_board_favorites')
                ->where('tenant_id', $this->tenant->id())
                ->where('user_id', $userId)
                ->delete();

            if ($boardId !== null) {
                DB::table('user_work_order_board_favorites')->insert([
                    'tenant_id' => $this->tenant->id(),
                    'user_id' => $userId,
                    'board_id' => $boardId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
