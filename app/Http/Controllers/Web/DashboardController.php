<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\DashboardController.
 *
 * Both call the same DashboardSummary service with the same period arguments,
 * so the Vue dashboard and the JSON dashboard cannot report different numbers.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardSummary $summary): Response
    {
        $period = $request->query('period');
        $range = $request->query('range');
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('Dashboard', [
            'summary' => $summary->build(
                is_string($period) ? $period : null,
                is_string($range) ? $range : null,
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            ),
            // Echoed back so the period selector can render its current state
            // without duplicating the defaults on the client.
            'filters' => [
                'period' => is_string($period) ? $period : null,
                'range' => is_string($range) ? $range : null,
                'from' => is_string($from) ? $from : null,
                'to' => is_string($to) ? $to : null,
            ],
        ]);
    }
}
