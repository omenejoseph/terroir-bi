<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\PresignUploadRequest;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Http\JsonResponse;

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
}
