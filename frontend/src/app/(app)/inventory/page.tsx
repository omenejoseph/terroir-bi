"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { BarChart3, ChevronDown, ClipboardCheck, Copy, Pencil, Plus, TrendingDown } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useBottleAnalyses, useDuplicateInventoryItem, useInventory } from "@/hooks/use-inventory";
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
import { ItemThumb } from "@/components/ui/item-thumb";
import { MetaField } from "@/components/ui/meta-field";
import { useConfirm } from "@/components/ui/confirm";
import { cn } from "@/lib/utils";
import { BulkEditInventory } from "@/components/inventory/bulk-edit-inventory";
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
  const [bulkEdit, setBulkEdit] = React.useState(false);
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
          {canManage && !bulkEdit && (
            <Button
              variant="outline"
              onClick={() => setBulkEdit(true)}
              disabled={items.length === 0}
              className="shrink-0"
            >
              <Pencil className="size-4" />
              {t("inventory.bulkEdit.trigger")}
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

      {bulkEdit ? (
        <BulkEditInventory items={items} onDone={() => setBulkEdit(false)} />
      ) : (
        <>
      {/* Category tabs */}
      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as CategoryTab)} />

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
        </>
      )}
    </div>
  );
}

function InventoryItemCard({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { number } = useFormatters();
  const router = useRouter();
  const duplicate = useDuplicateInventoryItem();
  const confirm = useConfirm();
  const canPricing = can("pricing.view");
  const [open, setOpen] = React.useState(false);
  const [detailTab, setDetailTab] = React.useState<DetailTab>("stock");

  async function onDuplicate() {
    const ok = await confirm({
      title: t("inventory.duplicate.confirmTitle"),
      description: t("inventory.duplicate.confirmBody", { name: item.name }),
      confirmLabel: t("inventory.details.duplicate"),
    });
    if (!ok) return;
    const created = await duplicate.mutateAsync(item.id);
    router.push(`/inventory/${created.id}`);
  }

  // Counts for the tab labels — only fetched once the card is expanded.
  const analysesQ = useBottleAnalyses(open ? item.id : undefined);
  const imagesQ = useInventoryImages(item.id, { enabled: open });
  const documentsQ = useInventoryDocuments(item.id, { enabled: open });

  // Tab order mirrors the prototype: Stock, Details, Recipe, Produce, Images, Docs, Pricing, Analysis.
  const tabs = [
    { value: "stock", label: t("inventory.stock.title") },
    { value: "overview", label: t("inventory.page.overview") },
    { value: "recipe", label: t("inventory.recipe.title") },
    { value: "produce", label: t("inventory.produce.title") },
    { value: "images", label: withCount(t("inventory.images.title"), imagesQ.data?.length) },
    { value: "documents", label: withCount(t("inventory.documents.title"), documentsQ.data?.length) },
    ...(canPricing ? [{ value: "pricing", label: t("inventory.pricing.title") }] : []),
    { value: "analysis", label: withCount(t("inventory.analysis.title"), analysesQ.data?.length) },
  ];

  // Stock "what's left", mirroring the prototype: present packaged goods in
  // bottles with the *number of cases* as the hint — "912 bottles (76 cases)",
  // not the bottles-per-case count.
  const unitLower = item.unit.trim().toLowerCase();
  const isCase = unitLower === "case" || unitLower === "cases";
  const bpc = item.bottles_per_case ?? 0;
  const stockNum = Number(item.current_stock);
  const bottles = Number.isFinite(stockNum) ? (isCase && bpc > 0 ? stockNum * bpc : stockNum) : null;
  const stockText = bottles != null ? number(Math.round(bottles)) : item.current_stock;
  const stockUnit = isCase ? t("inventory.summary.bottlesUnit") : item.unit;
  const casesCount = bottles != null && bpc > 1 ? Math.floor(bottles / bpc) : 0;
  const stockHint = casesCount > 0 ? t("inventory.summary.cases", { count: number(casesCount) }) : null;

  return (
    <Card className="overflow-hidden">
      <div className="flex w-full items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40">
        <button
          type="button"
          onClick={() => setOpen((prev) => !prev)}
          aria-expanded={open}
          className="flex min-w-0 flex-1 items-center gap-3 text-left"
        >
          <ItemThumb url={item.image_url} alt={item.name} />
          <div className="min-w-0">
            <p className="truncate font-medium">{item.name}</p>
            {/* Same columns as the prototype's inventory table — Size · Vintage · Stock — as labelled values. */}
            <div className="mt-0.5 flex flex-wrap gap-x-4 gap-y-0.5">
              {item.unit_size && (
                <MetaField label={t("inventory.summary.sizeLabel")}>{item.unit_size}</MetaField>
              )}
              {item.vintage != null && (
                <MetaField label={t("inventory.summary.vintageLabel")}>{item.vintage}</MetaField>
              )}
              <MetaField label={t("inventory.summary.stockLabel")}>
                {stockText} {stockUnit}
                {stockHint && <span className="font-normal text-muted-foreground"> ({stockHint})</span>}
              </MetaField>
            </div>
          </div>
        </button>
        <div className="flex shrink-0 items-center gap-2 text-sm">
          {!item.is_active && <Badge variant="secondary">{t("common.status.inactive")}</Badge>}
          {canManage && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => void onDuplicate()}
              disabled={duplicate.isPending}
            >
              {duplicate.isPending ? <Spinner className="size-4" /> : <Copy className="size-4" />}
              {t("inventory.details.duplicate")}
            </Button>
          )}
          <button
            type="button"
            onClick={() => setOpen((prev) => !prev)}
            aria-expanded={open}
            aria-label={open ? t("inventory.details.close") : t("inventory.details.view")}
            className="rounded-md p-1 text-muted-foreground hover:bg-muted"
          >
            <ChevronDown
              className={cn("size-4 transition-transform duration-300", open && "rotate-180")}
            />
          </button>
        </div>
      </div>

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

