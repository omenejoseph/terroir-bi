"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { BarChart3, ChevronDown, ClipboardCheck, Plus, TrendingDown } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useBottleAnalyses, useInventory } from "@/hooks/use-inventory";
import { useInventoryDocuments, useInventoryImages } from "@/hooks/use-inventory-media";
import { useTranslation } from "@/i18n/context";
import { withCount } from "@/lib/labels";
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
import { ItemOverviewSection } from "@/components/inventory/item-overview-section";
import { StockTab } from "@/components/inventory/stock-tab";
import { RecipeSection } from "@/components/inventory/recipe-section";
import { ProduceSection } from "@/components/inventory/produce-section";
import { PricingTab } from "@/components/inventory/pricing-tab";
import { AnalysisSection } from "@/components/inventory/analysis-section";
import { ImagesSection } from "@/components/inventory/images-section";
import { DocumentsSection } from "@/components/inventory/documents-section";

type CategoryTab = InventoryCategory | "ALL";
type DetailTab =
  | "overview"
  | "pricing"
  | "stock"
  | "recipe"
  | "produce"
  | "analysis"
  | "images"
  | "documents";

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
  const router = useRouter();
  const canManage = can("inventory.manage");
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
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("inventory.searchPlaceholder")}
            className="w-full sm:w-auto sm:max-w-xs"
          />
          <Button
            variant="outline"
            onClick={() => router.push("/inventory/analytics")}
            className="shrink-0"
          >
            <BarChart3 className="size-4" />
            {t("inventory.analytics.trigger")}
          </Button>
          <Button
            variant="outline"
            onClick={() => router.push("/inventory/spend")}
            className="shrink-0"
          >
            <TrendingDown className="size-4" />
            {t("inventory.spend.trigger")}
          </Button>
          {can("inventory.manage") && (
            <Button
              variant="outline"
              onClick={() => router.push("/inventory/check")}
              className="shrink-0"
            >
              <ClipboardCheck className="size-4" />
              {t("inventory.check.trigger")}
            </Button>
          )}
          {can("inventory.manage") && (
            <Button onClick={() => router.push("/inventory/new")} className="shrink-0">
              <Plus className="size-4" />
              {t("inventory.add.trigger")}
            </Button>
          )}
        </div>
      </header>

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
  const { can } = useAuth();
  const { moneyObject, number } = useFormatters();
  const canPricing = can("pricing.view");
  const [open, setOpen] = React.useState(false);
  const [detailTab, setDetailTab] = React.useState<DetailTab>("overview");

  // Counts for the tab labels — only fetched once the card is expanded.
  const analysesQ = useBottleAnalyses(open ? item.id : undefined);
  const imagesQ = useInventoryImages(item.id, { enabled: open });
  const documentsQ = useInventoryDocuments(item.id, { enabled: open });

  const tabs = [
    { value: "overview", label: t("inventory.page.overview") },
    ...(canPricing ? [{ value: "pricing", label: t("inventory.pricing.title") }] : []),
    { value: "stock", label: t("inventory.stock.title") },
    { value: "recipe", label: t("inventory.recipe.title") },
    { value: "produce", label: t("inventory.produce.title") },
    { value: "analysis", label: withCount(t("inventory.analysis.title"), analysesQ.data?.length) },
    { value: "images", label: withCount(t("inventory.images.title"), imagesQ.data?.length) },
    { value: "documents", label: withCount(t("inventory.documents.title"), documentsQ.data?.length) },
  ];

  // Stock "what's left" with a bottle hint: cases → total bottles, bottles → per case.
  const unitLower = item.unit.trim().toLowerCase();
  const isCase = unitLower === "case" || unitLower === "cases";
  const isBottle = unitLower === "bottle" || unitLower === "bottles";
  const stockNum = Number(item.current_stock);
  const stockText = Number.isFinite(stockNum) ? number(stockNum) : item.current_stock;
  const bpc = item.bottles_per_case ?? 0;
  const stockHint =
    isCase && bpc > 0 && Number.isFinite(stockNum)
      ? t("inventory.summary.totalBottles", { count: number(Math.round(stockNum * bpc)) })
      : isBottle && bpc > 0
        ? t("inventory.summary.perCase", { count: number(bpc) })
        : null;

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
          <p className="truncate text-xs text-muted-foreground">
            {item.sku}
            {item.vintage ? ` · ${item.vintage}` : ""}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-3 text-sm">
          <span className="tabular-nums">
            {stockText} {item.unit}
            {stockHint && <span className="text-muted-foreground"> ({stockHint})</span>}
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
                {detailTab === "pricing" && <PricingTab item={item} canManage={canManage} />}
                {detailTab === "stock" && <StockTab item={item} canManage={canManage} />}
                {detailTab === "recipe" && <RecipeSection item={item} canManage={canManage} />}
                {detailTab === "produce" && <ProduceSection item={item} canManage={canManage} />}
                {detailTab === "analysis" && <AnalysisSection item={item} canManage={canManage} />}
                {detailTab === "images" && <ImagesSection item={item} canManage={canManage} />}
                {detailTab === "documents" && (
                  <DocumentsSection item={item} canManage={canManage} />
                )}
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