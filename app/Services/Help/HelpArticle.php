<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * One help-center article in one language, parsed from
 * resources/help/{locale}/{id}.md (see HelpLibrary for the file format).
 */
final class HelpArticle
{
    /**
     * @param  list<string>  $screens  app paths whose "?" opens this article
     * @param  list<string>  $keywords  extra search terms (synonyms, the other language's word, …)
     * @param  list<string>  $related  ids of related articles
     * @param  list<string>  $headings  ## / ### headings, for search
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $topic,
        public readonly ?string $module,
        public readonly ?string $capability,
        public readonly array $screens,
        public readonly array $keywords,
        public readonly array $related,
        public readonly array $headings,
        public readonly string $html,
        public readonly string $text,
    ) {}

    /**
     * @return array{id: string, title: string, summary: string, topic: string, url: string}
     */
    public function summaryArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'topic' => $this->topic,
            'url' => '/help/'.$this->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromCache(array $row): self
    {
        /** @var array{id: string, title: string, summary: string, topic: string, module: ?string, capability: ?string, screens: list<string>, keywords: list<string>, related: list<string>, headings: list<string>, html: string, text: string} $row */
        return new self(...$row);
    }
}
