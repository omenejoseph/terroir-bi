"use client";

import * as React from "react";

import { ChevronDown, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useInventory } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import {
  INVENTORY_CATEGORIES,
  type InventoryCategory,
  type InventoryItem,
} from "@/lib/types";
import { useFormatters } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";
import { AddInventoryItemDialog } from "@/components/inventory/add-inventory-item-dialog";
import { InventoryCharts } from "@/components/inventory/inventory-charts";
import { ItemOverviewSection } from "@/components/inventory/item-overview-section";
import { StockSection } from "@/components/inventory/stock-section";
import { RecipeSection } from "@/components/inventory/recipe-section";
import { ImagesSection } from "@/components/inventory/images-section";

type CategoryTab = InventoryCategory | "ALL";
type DetailTab = "overview" | "stock" | "recipe" | "images";

interface SubBucket {
  subcategory: string | null;
  items: InventoryItem[];
}
interface GroupBucket {
  group: string | null;
  buckets: SubBucket[];
}

/** Group items by group, then subcategory. Nulls sort last (groups) / first (subs). */
function groupItems(items: InventoryItem[]): GroupBucket[] {
  const groups = new Map<string | null, Map<string | null, InventoryItem[]>>();
  for (const item of items) {
    const g = item.group ?? null;
    const s = item.subcategory ?? null;
    if (!groups.has(g)) groups.set(g, new Map());
    const subs = groups.get(g)!;
    if (!subs.has(s)) subs.set(s, []);
    subs.get(s)!.push(item);
  }

  const sortKeys = (keys: (string | null)[], nullsLast: boolean) =>
    [...keys].sort((a, b) => {
      if (a === null) return nullsLast ? 1 : -1;
      if (b === null) return nullsLast ? -1 : 1;
      return a.localeCompare(b);
    });

  return sortKeys([...groups.keys()], true).map((group) => {
    const subs = groups.get(group)!;
    return {
      group,
      buckets: sortKeys([...subs.keys()], false).map((subcategory) => ({
        subcategory,
        items: subs.get(subcategory)!,
      })),
    };
  });
}

export default function InventoryPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("inventory.manage");
  const [addOpen, setAddOpen] = React.useState(false);
  const [tab, setTab] = React.useState<CategoryTab>("ALL");
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");

  // Debounce the search input so we don't hit the API on every keystroke.
  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError, error } = useInventory({
    ...(debounced ? { search: debounced } : {}),
    ...(tab !== "ALL" ? { category: tab } : {}),
  });

  const items = data?.data ?? [];
  const grouped = React.useMemo(() => groupItems(items), [items]);

  const tabs: { value: CategoryTab; label: string }[] = [
    { value: "ALL", label: t("inventory.tabs.all") },
    ...INVENTORY_CATEGORIES.map((c) => ({ value: c, label: t(`inventory.category.${c}`) })),
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("inventory.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {data?.meta
              ? t("inventory.subtitleCount", { count: data.meta.total })
              : t("inventory.subtitleDefault")}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("inventory.searchPlaceholder")}
            className="sm:max-w-xs"
          />
          {can("inventory.manage") && (
            <Button onClick={() => setAddOpen(true)} className="shrink-0">
              <Plus className="size-4" />
              {t("inventory.add.trigger")}
            </Button>
          )}
        </div>
      </header>

      {/* Analytics charts */}
      <InventoryCharts />

      {/* Category tabs */}
      <div className="flex flex-wrap gap-1 border-b border-border">
        {tabs.map((tabItem) => {
          const active = tab === tabItem.value;
          return (
            <button
              key={tabItem.value}
              type="button"
              onClick={() => setTab(tabItem.value)}
              aria-pressed={active}
              className={cn(
                "relative px-3 py-2 text-sm font-medium transition-colors",
                active ? "text-primary" : "text-muted-foreground hover:text-foreground",
              )}
            >
              {tabItem.label}
              <span
                className={cn(
                  "absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-primary transition-opacity duration-200",
                  active ? "opacity-100" : "opacity-0",
                )}
              />
            </button>
          );
        })}
      </div>

      <AddInventoryItemDialog open={addOpen} onOpenChange={setAddOpen} />

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("inventory.errorForbidden")
              : t("inventory.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && items.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("inventory.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && items.length > 0 && (
        <div className="space-y-8">
          {grouped.map((group) => (
            <section key={group.group ?? "__ungrouped__"} className="space-y-3">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                {group.group ?? t("inventory.grouping.ungrouped")}
              </h2>
              {group.buckets.map((bucket) => (
                <div key={bucket.subcategory ?? "__none__"} className="space-y-2">
                  {bucket.subcategory && (
                    <h3 className="px-1 text-xs font-medium text-muted-foreground">
                      {bucket.subcategory}
                    </h3>
                  )}
                  {bucket.items.map((item) => (
                    <InventoryItemCard key={item.id} item={item} canManage={canManage} />
                  ))}
                </div>
              ))}
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

function InventoryItemCard({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const [open, setOpen] = React.useState(false);
  const [detailTab, setDetailTab] = React.useState<DetailTab>("overview");

  const tabs = [
    { value: "overview", label: t("inventory.page.overview") },
    { value: "stock", label: t("inventory.movements.title") },
    { value: "recipe", label: t("inventory.recipe.title") },
    { value: "images", label: t("inventory.images.title") },
  ];

  return (
    <Card className="overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
      >
        <div className="min-w-0">
          <p className="truncate font-medium">{item.name}</p>
          <p className="truncate text-xs text-muted-foreground">{item.sku}</p>
        </div>
        <div className="flex shrink-0 items-center gap-3 text-sm">
          <span className="tabular-nums">
            {item.current_stock} {item.unit}
          </span>
          <span className="hidden tabular-nums text-muted-foreground sm:inline">
            {moneyObject(item.default_price)}
          </span>
          <StatusBadges item={item} />
          <ChevronDown
            className={cn(
              "size-4 text-muted-foreground transition-transform duration-300",
              open && "rotate-180",
            )}
          />
        </div>
      </button>

      {/* Expandable dropdown panel with the tabbed detail */}
      <div
        className={cn(
          "grid transition-all duration-300 ease-out",
          open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0",
        )}
      >
        <div className="overflow-hidden">
          <div className="space-y-4 border-t border-border px-4 py-4">
            {open && (
              <>
                <Tabs tabs={tabs} value={detailTab} onChange={(v) => setDetailTab(v as DetailTab)} />
                {detailTab === "overview" && (
                  <ItemOverviewSection item={item} canManage={canManage} />
                )}
                {detailTab === "stock" && <StockSection item={item} canManage={canManage} />}
                {detailTab === "recipe" && <RecipeSection item={item} canManage={canManage} />}
                {detailTab === "images" && <ImagesSection item={item} canManage={canManage} />}
              </>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}

function StatusBadges({ item }: { item: InventoryItem }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-wrap gap-1">
      <Badge variant={item.is_active ? "success" : "secondary"}>
        {item.is_active ? t("common.status.active") : t("common.status.inactive")}
      </Badge>
      {item.is_for_sale && <Badge variant="outline">{t("common.forSale")}</Badge>}
    </div>
  );
}