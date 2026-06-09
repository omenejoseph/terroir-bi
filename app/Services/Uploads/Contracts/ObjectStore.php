<?php

declare(strict_types=1);

namespace App\Services\Uploads\Contracts;

/**
 * Abstraction over the object bucket so the upload flow can be exercised without
 * touching S3 in tests. The real implementation talks to an S3-compatible bucket.
 */
interface ObjectStore
{
    /**
     * A presigned PUT the browser uploads to directly. The Content-Type is baked
     * into the signature, so the bucket rejects a mismatched header.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPut(string $key, string $contentType, int $ttlSeconds): array;

    /** A short-lived presigned GET for reading a private object. */
    public function temporaryUrl(string $key, int $ttlSeconds): string;

    public function exists(string $key): bool;

    /** Object size in bytes, or null when it doesn't exist. */
    public function size(string $key): ?int;

    public function delete(string $key): void;
}
