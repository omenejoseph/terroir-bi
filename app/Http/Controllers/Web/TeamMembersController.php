<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Queries\ListTenantMembersQuery;
use Illuminate\Http\JsonResponse;

/**
 * The @-mention picker's member list (order comments today; any picker later).
 * Returns JSON rather than an Inertia page — the picker fetches this itself,
 * same reasoning as Web\SearchController.
 */
class TeamMembersController extends Controller
{
    public function index(ListTenantMembersQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->list()]);
    }
}
