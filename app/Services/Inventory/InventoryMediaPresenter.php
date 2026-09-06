<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryDocument;
use App\Models\InventoryImage;
use App\Models\InventoryTechSheet;
use App\Services\Uploads\PresignedUploadService;

/**
 * Turns one attached image, tech sheet or document into the shape both
 * Api\InventoryMediaController and Web\InventoryMediaController return: the
 * stored fields plus a short-lived presigned read URL (the bucket is
 * private), so the two transports can't disagree about what a client sees.
 */
class InventoryMediaPresenter
{
    public function __construct(private readonly PresignedUploadService $uploads) {}

    /**
     * @return array<string, mixed>
     */
    public function image(InventoryImage $image): array
    {
        return [
            'id' => $image->getKey(),
            'alt' => $image->alt,
            'content_type' => $image->content_type,
            'size_bytes' => $image->size_bytes,
            'sort_order' => $image->sort_order,
            'url' => $this->uploads->readUrl($image->object_key),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function techSheet(InventoryTechSheet $sheet): array
    {
        return [
            'id' => $sheet->getKey(),
            'name' => $sheet->name,
            'content_type' => $sheet->content_type,
            'size_bytes' => $sheet->size_bytes,
            'url' => $this->uploads->readUrl($sheet->object_key),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(InventoryDocument $document): array
    {
        return [
            'id' => $document->getKey(),
            'name' => $document->name,
            'content_type' => $document->content_type,
            'size_bytes' => $document->size_bytes,
            'url' => $this->uploads->readUrl($document->object_key),
        ];
    }
}
