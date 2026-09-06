<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\PresignUploadRequest;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Http\JsonResponse;

/**
 * The Inertia app's own presigned-upload endpoint — same policy as
 * Api\UploadController::presign() (open to any tenant member; the attach
 * step is what's gated), through the same PresignedUploadService, so a
 * purpose's MIME/size rules can't differ by which transport asked.
 *
 * Answers JSON rather than an Inertia page: the browser needs this URL to
 * upload the file directly to the bucket before anything else happens, so
 * it's a plain fetch from the page's own script (see NotificationController/
 * SearchController for the same JSON-from-a-web-route shape).
 */
class UploadController extends Controller
{
    public function presign(PresignUploadRequest $request, PresignedUploadService $service): JsonResponse
    {
        $payload = $service->presign(
            (string) $request->validated('purpose'),
            (string) $request->validated('filename'),
            (string) $request->validated('content_type'),
            (int) $request->validated('size'),
        );

        return response()->json(['data' => $payload]);
    }
}
