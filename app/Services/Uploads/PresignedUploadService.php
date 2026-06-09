<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Services\Uploads\Contracts\ObjectStore;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues presigned upload URLs and verifies the resulting objects. Security:
 *  - per-purpose MIME allowlist + hard size cap (cheap pre-check + bucket-side
 *    Content-Type enforcement baked into the signature);
 *  - the object key is generated server-side, namespaced under the tenant, with
 *    the extension derived solely from the authorized MIME type (never the
 *    client filename);
 *  - attaches must pass {@see assertOwnedKey()} so one tenant can't reference
 *    another tenant's object.
 */
class PresignedUploadService
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{key: string, url: string, method: string, headers: array<string, string>, content_type: string, max_bytes: int, expires_in: int}
     */
    public function presign(string $purpose, string $filename, string $contentType, int $size): array
    {
        $policy = $this->policy($purpose);

        if (! in_array($contentType, $policy['types'], true)) {
            throw ValidationException::withMessages([
                'content_type' => "Files of type {$contentType} are not allowed for {$purpose}.",
            ]);
        }

        if ($size < 1 || $size > $policy['max_bytes']) {
            throw ValidationException::withMessages([
                'size' => "File size must be between 1 byte and {$policy['max_bytes']} bytes.",
            ]);
        }

        $key = $this->key($policy['prefix'], $contentType);
        $ttl = (int) config('uploads.upload_ttl', 300);
        $presigned = $this->store->presignPut($key, $contentType, $ttl);

        return [
            'key' => $key,
            'url' => $presigned['url'],
            'method' => 'PUT',
            'headers' => $presigned['headers'],
            'content_type' => $contentType,
            'max_bytes' => $policy['max_bytes'],
            'expires_in' => $ttl,
        ];
    }

    /**
     * Confirm an uploaded object is present, within size, and owned by the
     * current tenant. Returns its byte size. Throws on any violation.
     */
    public function verifyOwnedObject(string $purpose, string $key): int
    {
        $policy = $this->policy($purpose);
        $this->assertOwnedKey($key);

        $size = $this->store->size($key);

        if ($size === null) {
            throw ValidationException::withMessages(['key' => 'The uploaded object was not found in the bucket.']);
        }

        if ($size > $policy['max_bytes']) {
            throw ValidationException::withMessages(['key' => 'The uploaded object exceeds the allowed size.']);
        }

        return $size;
    }

    public function readUrl(string $key): string
    {
        return $this->store->temporaryUrl($key, (int) config('uploads.read_ttl', 900));
    }

    public function delete(string $key): void
    {
        $this->store->delete($key);
    }

    public function tenantPrefix(): string
    {
        return 'tenants/'.$this->tenant->id().'/';
    }

    private function assertOwnedKey(string $key): void
    {
        if (! str_starts_with($key, $this->tenantPrefix())) {
            throw ValidationException::withMessages(['key' => 'That object key does not belong to this tenant.']);
        }
    }

    private function key(string $prefix, string $contentType): string
    {
        /** @var array<string, string> $extensions */
        $extensions = config('uploads.extensions', []);
        $ext = $extensions[$contentType] ?? 'bin';

        return $this->tenantPrefix().trim($prefix, '/').'/'.Str::ulid()->toBase32().'.'.$ext;
    }

    /**
     * @return array{types: list<string>, max_bytes: int, prefix: string}
     */
    private function policy(string $purpose): array
    {
        /** @var array<string, array{types: list<string>, max_bytes: int, prefix: string}> $purposes */
        $purposes = config('uploads.purposes', []);

        if (! isset($purposes[$purpose])) {
            throw ValidationException::withMessages(['purpose' => "Unknown upload purpose [{$purpose}]."]);
        }

        return $purposes[$purpose];
    }
}
