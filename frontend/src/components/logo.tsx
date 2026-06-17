import Image from "next/image";

import { APP_NAME } from "@/lib/config";
import { cn } from "@/lib/utils";

/**
 * The Terroir BI brand mark. Square lockup served from /public/icons. Pass
 * `light` for the light variant used on dark surfaces (e.g. the charcoal nav
 * chrome). Size it via `className` (e.g. "size-10"); aspect ratio is preserved.
 */
export function Logo({ className, light = false }: { className?: string; light?: boolean }) {
  return (
    <Image
      src={light ? "/icons/logo-light.png" : "/icons/logo.png"}
      alt={APP_NAME}
      width={512}
      height={512}
      priority
      className={cn("object-contain", className)}
    />
  );
}