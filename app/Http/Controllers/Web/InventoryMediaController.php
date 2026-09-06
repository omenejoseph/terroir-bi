<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AttachInventoryDocumentRequest;
use App\Http\Requests\Inventory\AttachInventoryImageRequest;
use App\Http\Requests\Inventory\AttachInventoryTechSheetRequest;
use App\Models\InventoryDocument;
use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\InventoryTechSheet;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Http\RedirectResponse;

/**
 * Attach bucket-stored media to inventory items (Product Detail · Images and
 * Docs tabs, Figma 449:1577) — the same PresignedUploadService verification
 * and validation Api\InventoryMediaController uses for the same three media
 * kinds. The object is uploaded directly via a presigned URL (see
 * Web\UploadController); attaching here only verifies and records the key.
 *
 * Reads don't get their own routes — InventoryController::show() sends the
 * item's images/tech sheets/documents alongside everything else on the page.
 */
class InventoryMediaController extends Controller
{
    public function __construct(private readonly PresignedUploadService $uploads) {}

    public function attachImage(AttachInventoryImageRequest $request, InventoryItem $item): RedirectResponse
    {
        $key = (string) $request->validated('key');
        $size = $this->uploads->verifyOwnedObject('inventory_image', $key);

        $item->images()->create([
            'object_key' => $key,
            'content_type' => (string) $request->validated('content_type'),
            'size_bytes' => $size,
            'alt' => $request->validated('alt'),
            'sort_order' => (int) $item->images()->max('sort_order') + 1,
        ]);

        return back()->with('success', __('Image added.'));
    }

    public function deleteImage(InventoryItem $item, InventoryImage $image): RedirectResponse
    {
        abort_unless($image->inventory_item_id === $item->getKey(), 404);

        $this->uploads->delete($image->object_key);
        $image->delete();

        return back()->with('success', __('Image removed.'));
    }

    public function attachTechSheet(AttachInventoryTechSheetRequest $request, InventoryItem $item): RedirectResponse
    {
        $key = (string) $request->validated('key');
        $size = $this->uploads->verifyOwnedObject('inventory_tech_sheet', $key);

        $item->techSheets()->create([
            'name' => (string) $request->validated('name'),
            'object_key' => $key,
            'content_type' => (string) $request->validated('content_type'),
            'size_bytes' => $size,
        ]);

        return back()->with('success', __('Tech sheet added.'));
    }

    public function deleteTechSheet(InventoryItem $item, InventoryTechSheet $techSheet): RedirectResponse
    {
        abort_unless($techSheet->inventory_item_id === $item->getKey(), 404);

        $this->uploads->delete($techSheet->object_key);
        $techSheet->delete();

        return back()->with('success', __('Tech sheet removed.'));
    }

    public function attachDocument(AttachInventoryDocumentRequest $request, InventoryItem $item): RedirectResponse
    {
        $key = (string) $request->validated('key');
        $size = $this->uploads->verifyOwnedObject('inventory_document', $key);

        $item->documents()->create([
            'name' => (string) $request->validated('name'),
            'object_key' => $key,
            'content_type' => (string) $request->validated('content_type'),
            'size_bytes' => $size,
        ]);

        return back()->with('success', __('Document added.'));
    }

    public function deleteDocument(InventoryItem $item, InventoryDocument $document): RedirectResponse
    {
        abort_unless($document->inventory_item_id === $item->getKey(), 404);

        $this->uploads->delete($document->object_key);
        $document->delete();

        return back()->with('success', __('Document removed.'));
    }
}
