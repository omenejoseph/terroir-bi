"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";

interface Item {
  href: string;
  key: string;
  /** Match by exact pathname (default also matches sub-paths). */
  exact?: boolean;
}

const ITEMS: Item[] = [
  { href: "/cellar", key: "map", exact: true },
  { href: "/cellar/lots", key: "lots" },
  { href: "/cellar/costs", key: "costs" },
  { href: "/cellar/analytics", key: "analytics" },
  { href: "/cellar/so2", key: "so2" },
  { href: "/cellar/tastings", key: "tastings" },
  { href: "/cellar/blend", key: "blend" },
  { href: "/cellar/bulk-additions", key: "bulkAdditions" },
  { href: "/cellar/bulk-analysis", key: "bulkAnalysis" },
  { href: "/cellar/products", key: "products" },
  { href: "/cellar/vessels", key: "vessels" },
];

/** Secondary navigation across the Cellar sub-pages (active = current path). */
export function CellarSubnav() {
  const { t } = useTranslation();
  const pathname = usePathname();

  return (
    <nav className="-mx-1 flex flex-wrap gap-1 overflow-x-auto rounded-lg bg-muted p-1">
      {ITEMS.map((item) => {
        const active = item.exact ? pathname === item.href : pathname.startsWith(item.href);
        return (
          <Link
            key={item.href}
            href={item.href}
            className={cn(
              "whitespace-nowrap rounded-md px-3 py-1.5 text-sm transition-all",
              active
                ? "bg-primary font-semibold text-primary-foreground shadow-sm"
                : "font-medium text-muted-foreground hover:text-foreground",
            )}
          >
            {t(`cellar.subnav.${item.key}`)}
          </Link>
        );
      })}
    </nav>
  );
}
