import { csrfHeader } from '@/lib/csrf';

/** Mirrors App\Services\Uploads\PresignedUploadService::presign()'s return shape. */
interface PresignedUpload {
    key: string;
    url: string;
    method: string;
    headers: Record<string, string>;
    content_type: string;
    max_bytes: number;
    expires_in: number;
}

/**
 * The presign → direct-PUT → attach flow every media tab uses (Product
 * Detail's Images and Docs tabs today; anything that adds a bucket upload
 * later reuses this rather than repeating it).
 *
 * Two requests, both outside Inertia's own router — this asks Web\
 * UploadController for a signed PUT URL for one `purpose` (a key into
 * config/uploads.php's per-purpose MIME/size policy), then uploads the file
 * straight to the bucket with it. The bucket never sees this app's session or
 * headers; this app's server never sees the file bytes. The caller does the
 * third step itself — an Inertia `useForm().post()` to the purpose's own
 * attach endpoint with the returned key — since that step also needs to
 * reload the page's own props.
 */
export async function uploadFile(file: File, purpose: string): Promise<{ key: string; content_type: string }> {
    const presignResponse = await fetch('/uploads/presign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify({ purpose, filename: file.name, content_type: file.type, size: file.size }),
    });

    if (!presignResponse.ok) {
        throw new Error(await firstError(presignResponse, 'Could not start the upload.'));
    }

    const presigned = ((await presignResponse.json()) as { data: PresignedUpload }).data;

    const putResponse = await fetch(presigned.url, {
        method: presigned.method,
        headers: presigned.headers,
        body: file,
    });

    if (!putResponse.ok) {
        throw new Error('The upload did not reach the bucket.');
    }

    return { key: presigned.key, content_type: presigned.content_type };
}

/** Laravel's validation-error shape, when the presign request itself is rejected. */
async function firstError(response: Response, fallback: string): Promise<string> {
    const body = (await response.json().catch(() => null)) as { errors?: Record<string, string[]> } | null;
    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;

    return first ?? fallback;
}
