<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Proxies an image to an external background-removal API (remove.bg-shaped by
 * default) and returns the processed PNG bytes. The API key lives only on the
 * server — the browser uploads the original and receives the cut-out back.
 */
class BackgroundRemovalService
{
    public function __construct(private readonly HttpFactory $http) {}

    /** Returns the background-removed image as PNG bytes. */
    public function remove(UploadedFile $file): string
    {
        /** @var array{endpoint: string, key: ?string, size: string} $config */
        $config = config('uploads.background_removal');

        if (empty($config['key'])) {
            throw ValidationException::withMessages([
                'image' => 'Background removal is not configured.',
            ]);
        }

        $contents = (string) file_get_contents($file->getRealPath());

        try {
            $response = $this->http
                ->withHeaders(['X-Api-Key' => $config['key']])
                ->timeout(30)
                ->attach('image_file', $contents, $file->getClientOriginalName() ?: 'image')
                ->post($config['endpoint'], [
                    'size' => $config['size'],
                    'format' => 'png',
                ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'image' => 'Could not reach the background-removal service. Try again later.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'image' => 'Background removal failed. Please try a different image.',
            ]);
        }

        return $response->body();
    }
}
