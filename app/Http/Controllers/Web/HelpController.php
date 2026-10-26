<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Help\HelpArticle;
use App\Services\Help\HelpLibrary;
use App\Services\Help\HelpSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The help center (docs/22-help-center-plan.md): a searchable library of
 * how-to articles, open to every member. HelpLibrary only hands out articles
 * about modules in the tenant's plan and actions the member can take, so
 * nothing here needs a capability gate of its own.
 */
class HelpController extends Controller
{
    public function __construct(private readonly HelpLibrary $library) {}

    public function index(): Response
    {
        $articles = $this->library->visible(app()->getLocale());

        $topics = [];
        foreach (HelpLibrary::TOPICS as $key => $label) {
            $inTopic = array_values(array_filter($articles, fn (HelpArticle $a): bool => $a->topic === $key));

            if ($inTopic !== []) {
                $topics[] = [
                    'key' => $key,
                    'label' => __($label),
                    'articles' => array_map(fn (HelpArticle $a): array => $a->summaryArray(), $inTopic),
                ];
            }
        }

        return Inertia::render('Help/Index', ['topics' => $topics]);
    }

    /** JSON for the Help page's search box (the ⌘K box gets articles through Web\SearchController). */
    public function search(Request $request, HelpSearch $search): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        return response()->json([
            'results' => mb_strlen($query) < 2 ? [] : $search->search($query, app()->getLocale()),
        ]);
    }

    public function show(string $article): Response
    {
        $locale = app()->getLocale();
        $found = $this->library->find($article, $locale);
        abort_unless($found instanceof HelpArticle, 404);

        $visible = $this->library->visible($locale);

        return Inertia::render('Help/Show', [
            'article' => [
                'id' => $found->id,
                'title' => $found->title,
                'summary' => $found->summary,
                'topic' => __(HelpLibrary::TOPICS[$found->topic] ?? $found->topic),
                // Rendered from our own Markdown with raw HTML stripped (HelpLibrary::parse()).
                'html' => $found->html,
            ],
            'related' => array_values(array_map(
                fn (string $id): array => $visible[$id]->summaryArray(),
                array_filter($found->related, fn (string $id): bool => isset($visible[$id])),
            )),
        ]);
    }
}
