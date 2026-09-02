<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Models\InventoryItem;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Turns a page of inventory items into the shape the clients render.
 *
 * Both transports use this: the API returns it as JSON, and the Inertia web
 * controller passes it straight through as a page prop. Keeping the mapping —
 * DTO fields, the signed lead-image URL, and the pagination envelope — in one
 * place is what lets the Vue table and the JSON API stay identical as fields
 * are added.
 */
class InventoryItemPresenter
{
    public function __construct(private readonly PresignedUploadService $uploads) {}

    /**
     * @param  LengthAwarePaginator<int, InventoryItem>  $paginator
     * @return array{data: list<array<string, mixed>>, meta: array{current_page:int, last_page:int, per_page:int, total:int}}
     */
    public function page(LengthAwarePaginator $paginator): array
    {
        /** @var list<InventoryItem> $items */
        $items = $paginator->items();

        // Lead image for the list thumbnail (one query for the whole page).
        // loadMissing mutates the same model instances returned by items().
        EloquentCollection::make($items)->loadMissing('firstImage');

        return [
            'data' => array_map(fn (InventoryItem $item): array => $this->item($item), $items),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * A single item, with its lead image resolved to a signed URL.
     *
     * @return array<string, mixed>
     */
    public function item(InventoryItem $item): array
    {
        $data = InventoryItemData::fromModel($item)->toArray();

        $key = $item->firstImage?->object_key;
        $data['image_url'] = $key !== null ? $this->uploads->readUrl($key) : null;

        return $data;
    }
}
