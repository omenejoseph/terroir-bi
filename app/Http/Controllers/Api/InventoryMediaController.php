<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AttachInventoryImageRequest;
use App\Http\Requests\Inventory\AttachInventoryTechSheetRequest;
use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\InventoryTechSheet;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Http\JsonResponse;

/**
 * Attach bucket-stored media to inventory items. The object is uploaded directly
 * via a presigned URL (see UploadController); attaching only verifies and records
 * the key. Reads are served as short-lived presigned GET URLs (private bucket).
 */
class InventoryMediaController extends Controller
{
    public function __construct(private readonly PresignedUploadService $uploads) {}

    public function listImages(InventoryItem $item): JsonResponse
    {
        $data = $item->images()->orderBy('sort_order')->get()
            ->map(fn (InventoryImage $image) => $this->presentImage($image))
            ->all();

        return response()->json(['data' => $data]);
    }

    public function attachImage(AttachInventoryImageRequest $request, InventoryItem $item): JsonResponse
    {
        $key = (string) $request->validated('key');
        $size = $this->uploads->verifyOwnedObject('inventory_image', $key);

        $image = $item->images()->create([
            'object_key' => $key,
            'content_type' => (string) $request->validated('content_type'),
            'size_bytes' => $size,
            'alt' => $request->validated('alt'),
            'sort_order' => (int) $item->images()->max('sort_order') + 1,
        ]);

        return response()->json(['data' => $this->presentImage($image)], 201);
    }

    public function deleteImage(InventoryItem $item, InventoryImage $image): JsonResponse
    {
        abort_unless($image->inventory_item_id === $item->getKey(), 404);

        $this->uploads->delete($image->object_key);
        $image->delete();

        return response()->json(status: 204);
    }

    public function listTechSheets(InventoryItem $item): JsonResponse
    {
        $data = $item->techSheets()->orderBy('name')->get()
            ->map(fn (InventoryTechSheet $sheet) => $this->presentSheet($sheet))
            ->all();

        return response()->json(['data' => $data]);
    }

    public function attachTechSheet(AttachInventoryTechSheetRequest $request, InventoryItem $item): JsonResponse
    {
        $key = (string) $request->validated('key');
        $size = $this->uploads->verifyOwnedObject('inventory_tech_sheet', $key);

        $sheet = $item->techSheets()->create([
            'name' => (string) $request->validated('name'),
            'object_key' => $key,
            'content_type' => (string) $request->validated('content_type'),
            'size_bytes' => $size,
        ]);

        return response()->json(['data' => $this->presentSheet($sheet)], 201);
    }

    public function deleteTechSheet(InventoryItem $item, InventoryTechSheet $techSheet): JsonResponse
    {
        abort_unless($techSheet->inventory_item_id === $item->getKey(), 404);

        $this->uploads->delete($techSheet->object_key);
        $techSheet->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentImage(InventoryImage $image): array
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
    private function presentSheet(InventoryTechSheet $sheet): array
    {
        return [
            'id' => $sheet->getKey(),
            'name' => $sheet->name,
            'content_type' => $sheet->content_type,
            'size_bytes' => $sheet->size_bytes,
            'url' => $this->uploads->readUrl($sheet->object_key),
        ];
    }
}
