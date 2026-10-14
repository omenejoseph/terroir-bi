<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Authorization\MembershipContext;
use App\Http\Controllers\Controller;
use App\Queries\CellarAnalyticsQuery;
use App\Queries\CellarBookQuery;
use App\Queries\CellarCostsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only cellar reports (costs roll-up + analytics) under the /cellar prefix. */
class CellarController extends Controller
{
    public function costs(CellarCostsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function analytics(CellarAnalyticsQuery $query, MembershipContext $membership): JsonResponse
    {
        return response()->json(['data' => $query->get(withCosts: $membership->can('finance.view'))]);
    }

    /**
     * The cellar book for a period (default: this year), as JSON or — with
     * ?format=csv — a CSV download (properly quoted).
     */
    public function book(Request $request, CellarBookQuery $query): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'format' => ['sometimes', 'in:json,csv'],
        ]);
        $from = Carbon::parse($data['from'] ?? now()->startOfYear()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->toDateString())->endOfDay();
        $rows = $query->get($from, $to);

        if (($data['format'] ?? 'json') !== 'csv') {
            return response()->json(['data' => $rows]);
        }

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Date', 'Lot', 'Name', 'Vintage', 'Event', 'Vessel', 'Quantity', 'Unit', 'Detail', 'By'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, array_values($r), ',', '"', '');
            }
            fclose($out);
        }, 'cellar-book-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
