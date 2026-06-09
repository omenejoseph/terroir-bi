<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Services\Uploads\Contracts\ObjectStore;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * S3-compatible object store backed by a configured filesystem disk. Presigned
 * PUTs include the Content-Type so the bucket validates the upload header.
 */
class S3ObjectStore implements ObjectStore
{
    public function __construct(private readonly string $disk) {}

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPut(string $key, string $contentType, int $ttlSeconds): array
    {
        $result = $this->disk()->temporaryUploadUrl(
            $key,
            now()->addSeconds($ttlSeconds),
            ['ContentType' => $contentType],
        );

        /** @var array<string, string> $headers */
        $headers = array_map(
            fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value,
            $result['headers'] ?? [],
        );

        // Guarantee the client is told to send the exact Content-Type we signed.
        $headers['Content-Type'] = $contentType;

        return ['url' => (string) $result['url'], 'headers' => $headers];
    }

    public function temporaryUrl(string $key, int $ttlSeconds): string
    {
        return $this->disk()->temporaryUrl($key, now()->addSeconds($ttlSeconds));
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function size(string $key): ?int
    {
        return $this->disk()->exists($key) ? $this->disk()->size($key) : null;
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk($this->disk);
    }
}
