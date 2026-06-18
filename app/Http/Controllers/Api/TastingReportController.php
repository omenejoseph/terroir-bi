<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\StoreTastingReportRequest;
use App\Models\TastingReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class TastingReportController extends Controller
{
    public function index(): JsonResponse
    {
        $reports = TastingReport::query()
            ->withCount('notes')
            ->orderByDesc('date')
            ->get();

        return response()->json([
            'data' => $reports->map(fn (TastingReport $r): array => [
                'id' => $r->getKey(),
                'title' => $r->title,
                'date' => $r->date->toIso8601String(),
                'note' => $r->note,
                'notes_count' => (int) $r->getAttribute('notes_count'),
            ])->all(),
        ]);
    }

    public function store(StoreTastingReportRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $report = TastingReport::create([
            'created_by_id' => $user->getKey(),
            'title' => $request->validated('title'),
            'date' => $request->validated('date') ?? now(),
            'note' => $request->validated('note'),
        ]);

        return response()->json([
            'data' => [
                'id' => $report->getKey(),
                'title' => $report->title,
                'date' => $report->date->toIso8601String(),
                'note' => $report->note,
                'notes_count' => 0,
            ],
        ], 201);
    }
}
