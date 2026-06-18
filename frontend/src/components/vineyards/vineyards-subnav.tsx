"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";

const ITEMS = [
  { href: "/vineyards", key: "parcels", exact: true },
  { href: "/vineyards/contracts", key: "contracts" },
  { href: "/vineyards/intake", key: "intake" },
];

/** Secondary navigation across the Vineyards sub-pages. */
export function VineyardsSubnav() {
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
            {t(`vineyards.subnav.${item.key}`)}
          </Link>
        );
      })}
    </nav>
  );
}
