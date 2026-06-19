<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Services\Uploads\PresignedUploadService;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads bundled stock files "through the app" so seeded media genuinely lands
 * in the configured uploads bucket (Cloudflare R2 by default).
 *
 * It mirrors the production handshake: ask {@see PresignedUploadService} for a
 * tenant-scoped object key (which validates the purpose, MIME and size), PUT the
 * bytes onto the same disk the presigned URL points at, then verify the object
 * is really in the bucket before the caller persists the DB row. Requires a
 * tenant to be bound in the current context (the key is namespaced per tenant).
 */
final class SeedMedia
{
    private readonly PresignedUploadService $uploads;

    /** @var array<string, array{key: string, size: int, content_type: string}> cache by abs path + purpose */
    private array $cache = [];

    public function __construct()
    {
        $this->uploads = app(PresignedUploadService::class);
    }

    public function imagesDir(): string
    {
        return dirname(__DIR__).'/assets/images';
    }

    /**
     * Upload an image bundled under database/seeders/assets/images and return
     * the object descriptor ready to spread into a media row.
     *
     * @return array{key: string, size: int, content_type: string}
     */
    public function image(string $filename, string $purpose = 'inventory_image'): array
    {
        return $this->put($this->imagesDir().'/'.$filename, $this->guessImageType($filename), $purpose);
    }

    /**
     * Generate a tiny but valid PDF on the fly and upload it. Used for the
     * document / tech-sheet purposes (PDF only) so no binary needs committing.
     *
     * @return array{key: string, size: int, content_type: string}
     */
    public function pdf(string $title, string $purpose): array
    {
        $bytes = $this->makePdf($title);
        $key = $this->presignAndStore('document.pdf', 'application/pdf', $bytes, $purpose);
        $size = $this->uploads->verifyOwnedObject($purpose, $key);

        return ['key' => $key, 'size' => $size, 'content_type' => 'application/pdf'];
    }

    /**
     * Upload an absolute file path with an explicit content type.
     *
     * @return array{key: string, size: int, content_type: string}
     */
    public function put(string $absPath, string $contentType, string $purpose): array
    {
        $cacheKey = $purpose.'|'.$absPath;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $bytes = (string) file_get_contents($absPath);
        $key = $this->presignAndStore(basename($absPath), $contentType, $bytes, $purpose);
        $size = $this->uploads->verifyOwnedObject($purpose, $key);

        return $this->cache[$cacheKey] = ['key' => $key, 'size' => $size, 'content_type' => $contentType];
    }

    private function presignAndStore(string $filename, string $contentType, string $bytes, string $purpose): string
    {
        // presign() generates the tenant-scoped key and enforces the purpose
        // policy (MIME allowlist + size cap) exactly as the real upload endpoint.
        $presigned = $this->uploads->presign($purpose, $filename, $contentType, strlen($bytes));
        $key = $presigned['key'];

        // PUT the bytes onto the same disk the presigned URL targets. Using the
        // disk directly is equivalent to the browser PUT but avoids a network
        // round-trip to the signed URL from the seeder process.
        Storage::disk((string) config('uploads.disk', 'r2'))->put($key, $bytes);

        return $key;
    }

    private function guessImageType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    /** Build a minimal single-page PDF with correct cross-reference offsets. */
    private function makePdf(string $title): string
    {
        $title = preg_replace('/[()\\\\]/', '', $title) ?? 'Document';
        $stream = "BT /F1 18 Tf 36 96 Td ({$title}) Tj 0 -28 Td /F1 11 Tf (Seeded demo document) Tj ET";

        $objects = [
            '<</Type /Catalog /Pages 2 0 R>>',
            '<</Type /Pages /Kids [3 0 R] /Count 1>>',
            '<</Type /Page /Parent 2 0 R /MediaBox [0 0 360 180] /Resources <</Font <</F1 5 0 R>>>> /Contents 4 0 R>>',
            '<</Length '.strlen($stream).">>\nstream\n{$stream}\nendstream",
            '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<</Size {$count} /Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}
