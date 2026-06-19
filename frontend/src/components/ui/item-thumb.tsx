import { Package } from "lucide-react";

/**
 * Small lead-image thumbnail for an inventory item (list rows, order line items);
 * falls back to a package icon when the item has no image. Uses a native <img>
 * rather than next/image because the URLs are short-lived presigned links.
 */
export function ItemThumb({ url, alt }: { url?: string | null; alt: string }) {
  return (
    <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted">
      {url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={url} alt={alt} className="size-full object-cover" />
      ) : (
        <Package className="size-4 text-muted-foreground" aria-hidden />
      )}
    </span>
  );
}
