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
     * @param  array<string, list<InventoryItem>>|null  $older  each product's older vintages (newest first), keyed by the
     *                                                          product's id — when given, they ride along as `previous_vintages`
     * @return array{data: list<array<string, mixed>>, meta: array{current_page:int, last_page:int, per_page:int, total:int}}
     */
    public function page(LengthAwarePaginator $paginator, ?array $older = null): array
    {
        /** @var list<InventoryItem> $items */
        $items = $paginator->items();

        // Lead image for the list thumbnail (one query for the whole page).
        // loadMissing mutates the same model instances returned by items().
        EloquentCollection::make([...$items, ...array_merge(...array_values($older ?? []))])->loadMissing('firstImage');

        return [
            'data' => array_map(function (InventoryItem $item) use ($older): array {
                $row = $this->item($item);

                if ($older === null) {
                    return $row;
                }

                $chain = $older[$item->getKey()] ?? [];
                $row['previous_vintages'] = array_map(
                    fn (InventoryItem $previous, int $at): array => $this->item($previous, array_slice($chain, $at + 1)),
                    $chain,
                    array_keys($chain),
                );

                // A new vintage has no photo of its own yet: it shows the one its predecessors carry.
                $row['image_url'] ??= $this->inheritedImage($chain);

                return $row;
            }, $items),
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
     * @param  list<InventoryItem>|null  $older  its older vintages when the caller already has them (saves a walk)
     * @return array<string, mixed>
     */
    public function item(InventoryItem $item, ?array $older = null): array
    {
        $data = InventoryItemData::fromModel($item)->toArray();

        $key = $item->firstImage?->object_key;
        $data['image_url'] = $key !== null ? $this->uploads->readUrl($key) : null;

        // No photo of its own: borrow the nearest older vintage's, so a new vintage needs none added.
        if ($data['image_url'] === null) {
            $data['image_url'] = $older !== null ? $this->inheritedImage($older) : $this->inheritedImageFor($item);
        }

        return $data;
    }

    /** @param  list<InventoryItem>  $older */
    private function inheritedImage(array $older): ?string
    {
        foreach ($older as $previous) {
            $key = $previous->firstImage?->object_key;

            if ($key !== null) {
                return $this->uploads->readUrl($key);
            }
        }

        return null;
    }

    /** Walks back through the item's earlier vintages for a photo — only when it has none itself. */
    private function inheritedImageFor(InventoryItem $item): ?string
    {
        $cursor = $item->previous_vintage_id;

        for ($depth = 0; $cursor !== null && $depth < 50; $depth++) {
            $previous = InventoryItem::query()->with('firstImage')->find($cursor);
            $key = $previous?->firstImage?->object_key;

            if ($key !== null) {
                return $this->uploads->readUrl($key);
            }

            $cursor = $previous?->previous_vintage_id;
        }

        return null;
    }
}
