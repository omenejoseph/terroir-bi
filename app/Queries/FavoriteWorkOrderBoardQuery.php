<?php

declare(strict_types=1);

namespace App\Queries;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/** A member's one favourite Work Orders board — see SetFavoriteWorkOrderBoardAction. */
class FavoriteWorkOrderBoardQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function get(string $userId): ?string
    {
        /** @var string|null $boardId */
        $boardId = DB::table('user_work_order_board_favorites')
            ->where('tenant_id', $this->tenant->id())
            ->where('user_id', $userId)
            ->value('board_id');

        return $boardId;
    }
}
