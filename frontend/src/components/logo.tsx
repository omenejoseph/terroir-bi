import Image from "next/image";

import { APP_NAME } from "@/lib/config";
import { cn } from "@/lib/utils";

/**
 * The Terroir BI brand mark. Square lockup served from /public/icons/logo.png.
 * Size it via `className` (e.g. "size-10"); the aspect ratio is preserved.
 */
export function Logo({ className }: { className?: string }) {
  return (
    <Image
      src="/icons/logo.png"
      alt={APP_NAME}
      width={512}
      height={512}
      priority
      className={cn("object-contain", className)}
    />
  );
}