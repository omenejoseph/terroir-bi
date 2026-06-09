<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Uploads\Contracts\ObjectStore;

/**
 * In-memory ObjectStore for tests: records presign calls and lets a test mark an
 * object as "uploaded" so the attach/verify flow can run without S3.
 */
class FakeObjectStore implements ObjectStore
{
    /** @var array<string, int> object key => size in bytes */
    public array $objects = [];

    /** @var list<array{key: string, content_type: string}> */
    public array $presigned = [];

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPut(string $key, string $contentType, int $ttlSeconds): array
    {
        $this->presigned[] = ['key' => $key, 'content_type' => $contentType];

        return [
            'url' => "https://bucket.example/{$key}?X-Amz-Signature=fake&X-Amz-Expires={$ttlSeconds}",
            'headers' => ['Content-Type' => $contentType, 'Host' => 'bucket.example'],
        ];
    }

    public function temporaryUrl(string $key, int $ttlSeconds): string
    {
        return "https://bucket.example/{$key}?X-Amz-Signature=read";
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function size(string $key): ?int
    {
        return $this->objects[$key] ?? null;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    /** Mark an object as present in the bucket (simulating a completed upload). */
    public function store(string $key, int $size): void
    {
        $this->objects[$key] = $size;
    }
}
