import Link from "next/link";
import { ChevronRight } from "lucide-react";

import { cn } from "@/lib/utils";

export interface Crumb {
  label: string;
  /** Omit on the last (current) crumb to render it as plain text. */
  href?: string;
}

/**
 * A simple breadcrumb trail (muted links separated by chevrons). The last crumb
 * is the current page and is not a link. Token-driven, matches the house style.
 */
export function Breadcrumb({ items, className }: { items: Crumb[]; className?: string }) {
  return (
    <nav aria-label="Breadcrumb" className={cn("flex items-center gap-1 text-sm text-muted-foreground", className)}>
      {items.map((item, i) => {
        const last = i === items.length - 1;
        return (
          <span key={`${item.label}-${i}`} className="flex items-center gap-1">
            {item.href && !last ? (
              <Link href={item.href} className="transition-colors hover:text-foreground">
                {item.label}
              </Link>
            ) : (
              <span className={cn(last && "font-medium text-foreground")}>{item.label}</span>
            )}
            {!last && <ChevronRight className="size-3.5" />}
          </span>
        );
      })}
    </nav>
  );
}
