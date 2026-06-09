<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\PresignUploadRequest;
use App\Http\Requests\Uploads\RemoveBackgroundRequest;
use App\Services\Uploads\BackgroundRemovalService;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/**
 * General presigned-upload endpoint: the frontend asks for a URL, uploads the
 * file directly to the bucket, then attaches the returned `key` to a resource.
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

    /**
     * Proxy an image through the background-removal service and stream the
     * processed PNG back. The browser then crops/resizes and uploads it via the
     * normal presign flow.
     */
    public function removeBackground(RemoveBackgroundRequest $request, BackgroundRemovalService $service): Response
    {
        /** @var UploadedFile $image */
        $image = $request->file('image');

        $png = $service->remove($image);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }
}
