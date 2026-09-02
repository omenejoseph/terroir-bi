<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads the work-order board's filters off a request.
 *
 * `due_soon` and `mine` are the board's two toggles (Figma 267:1781). They are
 * carried as intents rather than resolved values so the page can render them as
 * pressed; the controller turns them into a date ceiling and an assignee, which
 * is what ListWorkOrdersQuery actually understands.
 */
final class WorkOrderFilters
{
    /**
     * @return array{
     *     search: ?string, category: ?string, status: ?string, assignee_id: ?string,
     *     due_soon: bool, mine: bool, recurring: bool,
     * }
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'search' => self::str($request->query('search')),
            'category' => self::str($request->query('category')),
            'status' => self::str($request->query('status')),
            'assignee_id' => self::str($request->query('assignee_id')),
            'due_soon' => $request->boolean('due_soon'),
            'mine' => $request->boolean('mine'),
            // Carried so the toggle can render, but it filters nothing: there
            // is no recurrence on a work order. See docs/design/README.md.
            'recurring' => $request->boolean('recurring'),
        ];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
