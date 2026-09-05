<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-admin home — port of App\Filament\Pages\Dashboard and its
 * widgets. Widgets land in Tier 3 (see the plan); this is the Tier 0
 * placeholder that proves the routing/layout/auth plumbing end to end.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/Dashboard/Index');
    }
}
