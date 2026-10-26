<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The header's global search results, grouped by category. Every field
 * defaults to empty so an unauthorized or query-too-short response is just
 * `new self()` — the frontend always receives the same three keys rather than
 * having to guard against a category being absent.
 *
 * @implements Arrayable<string, mixed>
 */
final class SearchResultsData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<SearchResultData>  $orders
     * @param  list<SearchResultData>  $customers
     * @param  list<SearchResultData>  $inventory
     * @param  list<SearchResultData>  $cellar  wine lots and vessels
     * @param  list<SearchResultData>  $help  help-center articles (App\Services\Help\HelpSearch)
     */
    public function __construct(
        public readonly array $orders = [],
        public readonly array $customers = [],
        public readonly array $inventory = [],
        public readonly array $cellar = [],
        public readonly array $help = [],
    ) {}

    /**
     * @param  list<SearchResultData>  $help
     */
    public function withHelp(array $help): self
    {
        return new self($this->orders, $this->customers, $this->inventory, $this->cellar, $help);
    }

    /**
     * @return array{
     *     orders: list<array{id: string, title: string, subtitle: string, url: string}>,
     *     customers: list<array{id: string, title: string, subtitle: string, url: string}>,
     *     inventory: list<array{id: string, title: string, subtitle: string, url: string}>,
     *     cellar: list<array{id: string, title: string, subtitle: string, url: string}>,
     *     help: list<array{id: string, title: string, subtitle: string, url: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'orders' => array_map(fn (SearchResultData $result): array => $result->toArray(), $this->orders),
            'customers' => array_map(fn (SearchResultData $result): array => $result->toArray(), $this->customers),
            'inventory' => array_map(fn (SearchResultData $result): array => $result->toArray(), $this->inventory),
            'cellar' => array_map(fn (SearchResultData $result): array => $result->toArray(), $this->cellar),
            'help' => array_map(fn (SearchResultData $result): array => $result->toArray(), $this->help),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
