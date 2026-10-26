<?php

declare(strict_types=1);

namespace App\Services\Help;

use Illuminate\Support\Str;

/**
 * Searches the help articles a member can see.
 *
 * Text is folded before matching — lower-cased and transliterated, so
 * "racun" finds "račun" and "Đ" matches "d". A query word matches a whole
 * word or the start of one ("impor" → "import"), or any of its synonyms.
 * Every query word must match somewhere in an article; where it matches sets
 * the score (title ≫ keywords > headings > summary > body).
 *
 * At a few dozen articles this is well under a millisecond and needs no
 * search service. Should the library grow into the hundreds, swap this class
 * for Scout + Meilisearch behind the same search() signature.
 */
class HelpSearch
{
    private const WEIGHTS = ['title' => 10, 'keywords' => 6, 'headings' => 4, 'summary' => 3, 'body' => 1];

    /**
     * Words people use for the same thing. Each group is matched as one —
     * searching for any of them finds articles using any other. Folded form.
     */
    private const SYNONYMS = [
        ['rebate', 'discount', 'rabat', 'popust'],
        ['invoice', 'racun', 'bill'],
        ['bottle', 'bottles', 'boca', 'boce'],
        ['case', 'cases', 'kutija', 'karton'],
        ['stock', 'inventory', 'zaliha', 'zalihe', 'skladiste'],
        ['customer', 'customers', 'client', 'kupac', 'kupci'],
        ['order', 'orders', 'narudzba', 'narudzbe'],
        ['price', 'prices', 'pricing', 'cijena', 'cijene'],
        ['tier', 'tiers', 'razina', 'razine', 'price list', 'cjenik'],
        ['team', 'user', 'users', 'colleague', 'tim', 'korisnik', 'korisnici'],
        ['invite', 'invitation', 'pozivnica', 'pozovi'],
        ['delete', 'remove', 'obrisi', 'ukloni'],
        ['link', 'portal', 'poveznica'],
        ['pdf', 'print', 'ispis'],
        ['ship', 'shipped', 'shipping', 'delivery', 'otprema', 'isporuka', 'dostava'],
    ];

    /** Question words that say nothing about the topic ("how do I …", "kako da …"). Folded. */
    private const STOP_WORDS = [
        'the', 'and', 'or', 'to', 'how', 'do', 'does', 'can', 'is', 'are', 'in', 'on', 'of', 'for', 'my', 'an', 'it', 'what', 'where', 'why', 'with',
        'kako', 'da', 'je', 'se', 'na', 'za', 'od', 'sto', 'gdje', 'li', 'mogu', 'moj', 'moja', 'ili',
    ];

    public function __construct(private readonly HelpLibrary $library) {}

    /**
     * @return list<array{id: string, title: string, summary: string, topic: string, url: string, snippet: string}>
     */
    public function search(string $query, string $locale, int $limit = 8): array
    {
        $all = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', self::fold($query)) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 2,
        ));
        $meaningful = array_values(array_diff($all, self::STOP_WORDS));
        // A query of nothing but stop words is still searched as typed.
        $words = $meaningful !== [] ? $meaningful : $all;

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach ($this->library->visible($locale) as $article) {
            $fields = [
                'title' => self::fold($article->title),
                'keywords' => self::fold(implode(' ', $article->keywords)),
                'headings' => self::fold(implode(' ', $article->headings)),
                'summary' => self::fold($article->summary),
                'body' => self::fold($article->text),
            ];

            $score = 0;

            foreach ($words as $word) {
                $best = 0;

                foreach (self::WEIGHTS as $field => $weight) {
                    if ($this->matches($fields[$field], $word)) {
                        $best = max($best, $weight);
                    }
                }

                if ($best === 0) {
                    continue 2; // every word must match somewhere
                }

                $score += $best;
            }

            $scored[] = ['score' => $score, 'article' => $article, 'words' => $words];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['article']->title, $b['article']->title));

        return array_map(
            fn (array $hit): array => $hit['article']->summaryArray() + ['snippet' => $this->snippet($hit['article'], $hit['words'])],
            array_slice($scored, 0, $limit),
        );
    }

    /** Lower-case ASCII: "Račun" → "racun", "Đurđa" → "durda". */
    public static function fold(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }

    /** The word, or one of its synonyms, at the start of a word in the text. */
    private function matches(string $haystack, string $word): bool
    {
        foreach ($this->variants($word) as $variant) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($variant, '/').'/', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function variants(string $word): array
    {
        foreach (self::SYNONYMS as $group) {
            foreach ($group as $synonym) {
                if (str_starts_with($synonym, $word) || $synonym === $word) {
                    return array_values(array_unique([$word, ...$group]));
                }
            }
        }

        return [$word];
    }

    /**
     * ~160 characters of body text around the first matching word, or the
     * summary when the match was only in the title or keywords.
     *
     * @param  list<string>  $words
     */
    private function snippet(HelpArticle $article, array $words): string
    {
        $folded = self::fold($article->text);

        foreach ($words as $word) {
            foreach ($this->variants($word) as $variant) {
                $at = mb_strpos($folded, $variant);

                if ($at !== false) {
                    $start = max(0, $at - 60);
                    $excerpt = mb_substr($article->text, $start, 160);

                    return ($start > 0 ? '…' : '').trim($excerpt).(mb_strlen($article->text) > $start + 160 ? '…' : '');
                }
            }
        }

        return $article->summary;
    }
}
