<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Authorization\MembershipContext;
use App\Authorization\TenantModules;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Cache;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;

/**
 * The help center's articles: Markdown files in resources/help/{locale}/,
 * one per article, the file name being the article id shared by every
 * language. Each starts with a front-matter block:
 *
 *     ---
 *     title: Pricing tiers and rebates
 *     summary: Price books for groups of customers.
 *     topic: customers               (one of TOPICS)
 *     module: customers              (App\Enums\Module key; omit = everyone)
 *     capability: customers.manage   (optional)
 *     screens: /customers/pricing-tiers, /customers
 *     keywords: rabat, discount, popust
 *     related: how-prices-work, add-customers
 *     ---
 *
 * Parsed once per locale and cached until a file changes. visible() and
 * find() only ever return articles about modules in the tenant's plan and
 * actions the member can take, so help never points somewhere they can't go.
 */
class HelpLibrary
{
    /** Topic key => label (a translation key), in the order the Help page shows them. */
    public const TOPICS = [
        'getting-started' => 'Getting started',
        'orders' => 'Orders',
        'customers' => 'Customers & pricing',
        'inventory' => 'Inventory',
        'cellar' => 'Cellar',
        'work-orders' => 'Work orders',
        'settings' => 'Settings & team',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly MembershipContext $membership,
    ) {}

    /**
     * Every article in the locale (falling back to English), unfiltered.
     *
     * @return array<string, HelpArticle> keyed by id
     */
    public function all(string $locale): array
    {
        $locale = $this->resolveLocale($locale);
        $files = glob($this->directory($locale).'/*.md') ?: [];
        $signature = md5(implode('|', array_map(fn (string $f): string => basename($f).':'.filemtime($f), $files)));

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::rememberForever("help:{$locale}:{$signature}", fn (): array => array_map(
            fn (string $file): array => $this->parse($file)->toCache(),
            $files,
        ));

        $articles = [];
        foreach ($rows as $row) {
            $article = HelpArticle::fromCache($row);
            $articles[$article->id] = $article;
        }

        return $articles;
    }

    /**
     * The articles this member may see in the current tenant.
     *
     * @return array<string, HelpArticle>
     */
    public function visible(string $locale): array
    {
        $modules = TenantModules::keysFor($this->tenants->current());

        return array_filter(
            $this->all($locale),
            fn (HelpArticle $a): bool => ($a->module === null || in_array($a->module, $modules, true))
                && ($a->capability === null || $this->membership->can($a->capability)),
        );
    }

    public function find(string $id, string $locale): ?HelpArticle
    {
        return $this->visible($locale)[$id] ?? null;
    }

    /** The article whose `screens` best match this path (longest prefix wins). */
    public function forScreen(string $path, string $locale): ?HelpArticle
    {
        $best = null;
        $bestLength = 0;

        foreach ($this->visible($locale) as $article) {
            foreach ($article->screens as $screen) {
                $matches = $path === $screen || str_starts_with($path, rtrim($screen, '/').'/');

                if ($matches && strlen($screen) > $bestLength) {
                    $best = $article;
                    $bestLength = strlen($screen);
                }
            }
        }

        return $best;
    }

    /** @return list<string> the locales that have a help folder */
    public static function locales(): array
    {
        return array_values(array_map('basename', glob(resource_path('help').'/*', GLOB_ONLYDIR) ?: []));
    }

    private function resolveLocale(string $locale): string
    {
        return is_dir($this->directory($locale)) ? $locale : 'en';
    }

    private function directory(string $locale): string
    {
        return resource_path('help/'.$locale);
    }

    private function parse(string $file): HelpArticle
    {
        $raw = (string) file_get_contents($file);

        if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $parts)) {
            throw new RuntimeException("Help article {$file} has no front matter.");
        }

        $meta = [];
        foreach (preg_split('/\R/', $parts[1]) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $m)) {
                $meta[$m[1]] = trim($m[2]);
            }
        }

        $markdown = $parts[2];
        $html = (new GithubFlavoredMarkdownConverter([
            // Articles are ours, but nothing in one should ever be able to run script.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        preg_match_all('/^#{2,3}\s+(.+)$/m', $markdown, $headings);

        $list = fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', $meta[$key] ?? '')), fn (string $v): bool => $v !== ''));

        return new HelpArticle(
            id: basename($file, '.md'),
            title: $meta['title'] ?? basename($file, '.md'),
            summary: $meta['summary'] ?? '',
            topic: $meta['topic'] ?? 'getting-started',
            module: ($meta['module'] ?? '') !== '' ? $meta['module'] : null,
            capability: ($meta['capability'] ?? '') !== '' ? $meta['capability'] : null,
            screens: $list('screens'),
            keywords: $list('keywords'),
            related: $list('related'),
            headings: array_values(array_map('trim', $headings[1])),
            html: $html,
            text: trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)))),
        );
    }
}
