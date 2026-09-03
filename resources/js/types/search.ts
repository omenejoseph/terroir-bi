/**
 * The header's global search. Mirrors App\DataTransferObjects\SearchResultData
 * / SearchResultsData — GET /search's JSON shape.
 */

export interface SearchResult {
    id: string;
    title: string;
    subtitle: string;
    url: string;
}

export interface SearchResults {
    orders: SearchResult[];
    customers: SearchResult[];
    inventory: SearchResult[];
}
