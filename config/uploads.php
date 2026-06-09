<?php

declare(strict_types=1);

return [
    // Filesystem disk used for direct-to-bucket uploads (see config/filesystems.php).
    // Defaults to Cloudflare R2; override with UPLOADS_DISK.
    'disk' => env('UPLOADS_DISK', 'r2'),

    // Lifetimes (seconds) for the presigned PUT (upload) and GET (read) URLs.
    'upload_ttl' => (int) env('UPLOADS_UPLOAD_TTL', 300),
    'read_ttl' => (int) env('UPLOADS_READ_TTL', 900),

    /*
    | Per-purpose upload policy. Each purpose pins an allowlist of MIME types, a
    | hard max size, and the key prefix under the tenant's namespace. The MIME
    | type is baked into the presigned PUT signature, so the bucket rejects an
    | upload whose Content-Type header doesn't match what we authorized.
    */
    'purposes' => [
        'inventory_image' => [
            'types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'max_bytes' => 5 * 1024 * 1024,
            'prefix' => 'inventory/images',
        ],
        'inventory_tech_sheet' => [
            'types' => ['application/pdf'],
            'max_bytes' => 20 * 1024 * 1024,
            'prefix' => 'inventory/tech-sheets',
        ],
    ],

    // MIME type → file extension (the only source of the stored object's extension).
    'extensions' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ],
];
