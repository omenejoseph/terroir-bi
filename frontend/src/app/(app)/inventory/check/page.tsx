"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, ChevronLeft, ChevronRight } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useApplyInventoryCheck,
  useInventory,
  useInventoryCheck,
  useInventoryChecks,
} from "@/hooks/use-inventory";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { INVENTORY_CATEGORIES, type InventoryItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";

type Mode = "check" | "history";

export default function InventoryCheckPage() {
  const { t } = useTranslation();
  const [mode, setMode] = React.useState<Mode>("check");

  return (
    <div className="space-y-5">
      <Link
        href="/inventory"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("inventory.page.back")}
      </Link>

      <div className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t("inventory.check.title")}</h1>
        <div className="flex rounded-md border border-border p-0.5 text-sm">
          {(["check", "history"] as const).map((m) => (
            <button
              key={m}
              type="button"
              onClick={() => setMode(m)}
              className={cn(
                "rounded px-3 py-1 font-medium transition-colors",
                mode === m ? "bg-primary text-primary-foreground" : "text-muted-foreground",
              )}
            >
              {t(m === "check" ? "inventory.check.tabCheck" : "inventory.check.tabHistory")}
            </button>
          ))}
        </div>
      </div>

      {mode === "check" ? <CheckGrid /> : <CheckHistory />}
    </div>
  );
}

function CheckGrid() {
  const { t } = useTranslation();
  const apply = useApplyInventoryCheck();

  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [category, setCategory] = React.useState<string>("");
  const [page, setPage] = React.useState(1);
  const [counts, setCounts] = React.useState<Map<string, string>>(new Map());
  const [error, setError] = React.useState<string | null>(null);
  const [applied, setApplied] = React.useState<number | null>(null);

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);
  React.useEffect(() => setPage(1), [debounced, category]);

  const { data, isLoading } = useInventory({
    ...(debounced ? { search: debounced } : {}),
    ...(category ? { category } : {}),
    page,
  });
  const items = data?.data ?? [];
  const lastPage = data?.meta?.last_page ?? 1;

  // Number of entered counts that differ from the system stock (will adjust).
  const changeCount = React.useMemo(() => {
    let n = 0;
    for (const [, v] of counts) if (v.trim() !== "") n += 1;
    return n;
  }, [counts]);

  function setCount(id: string, value: string) {
    setApplied(null);
    setCounts((prev) => {
      const next = new Map(prev);
      if (value.trim() === "") next.delete(id);
      else next.set(id, value);
      return next;
    });
  }

  async function onApply() {
    setError(null);
    const entries = [...counts.entries()]
      .filter(([, v]) => v.trim() !== "" && Number.isFinite(Number(v)))
      .map(([item_id, v]) => ({ item_id, physical_count: Number(v) }));
    if (entries.length === 0) return;
    try {
      const result = await apply.mutateAsync({ items: entries });
      const adjusted = result.filter((r) => Number(r.difference) !== 0).length;
      setApplied(adjusted);
      setCounts(new Map());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.check.errorGeneric"));
    }
  }

  const categories = [
    { value: "", label: t("inventory.check.filterAll") },
    ...INVENTORY_CATEGORIES.map((c) => ({ value: c, label: t(`inventory.category.${c}`) })),
  ];

  // Group the current page by category → group · subcategory.
  const sections = React.useMemo(() => groupByCategory(items), [items]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("inventory.check.searchPlaceholder")}
          className="sm:max-w-xs"
        />
        <div className="flex flex-wrap gap-1">
          {categories.map((c) => (
            <button
              key={c.value || "all"}
              type="button"
              onClick={() => setCategory(c.value)}
              aria-pressed={category === c.value}
              className={cn(
                "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
                category === c.value
                  ? "border-primary bg-primary/10 text-primary"
                  : "border-border text-muted-foreground hover:text-foreground",
              )}
            >
              {c.label}
            </button>
          ))}
        </div>
        <div className="ml-auto flex items-center gap-2">
          <Pager page={page} lastPage={lastPage} onChange={setPage} />
          <Button onClick={onApply} disabled={apply.isPending || changeCount === 0}>
            {apply.isPending && <Spinner />}
            {t("inventory.check.apply", { count: changeCount })}
          </Button>
        </div>
      </div>

      {applied != null && (
        <p className="rounded-md bg-success/10 px-3 py-2 text-sm text-success">
          {t("inventory.check.applied", { count: applied })}
        </p>
      )}
      {error && <p className="text-sm text-destructive">{error}</p>}

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : items.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            {t("inventory.check.empty")}
          </CardContent>
        </Card>
      ) : (
        sections.map((section) => (
          <Card key={section.category}>
            <CardContent className="space-y-3 pt-6">
              <h2 className="text-sm font-semibold">
                {t(`inventory.check.section.${section.category}`)}
              </h2>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="border-b border-border text-left text-xs uppercase text-muted-foreground">
                    <tr>
                      <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.product")}</th>
                      <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.size")}</th>
                      <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.vintage")}</th>
                      <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.unit")}</th>
                      <th className="py-2 pr-3 text-right font-medium">{t("inventory.check.cols.system")}</th>
                      <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.physical")}</th>
                      <th className="py-2 text-right font-medium">{t("inventory.check.cols.difference")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {section.groups.map((g) => (
                      <React.Fragment key={g.label ?? "__none__"}>
                        {g.label && (
                          <tr>
                            <td colSpan={7} className="px-1 pt-2 text-xs font-medium text-muted-foreground">
                              {g.label}
                            </td>
                          </tr>
                        )}
                        {g.items.map((item) => (
                          <CountRow
                            key={item.id}
                            item={item}
                            value={counts.get(item.id) ?? ""}
                            onChange={(v) => setCount(item.id, v)}
                          />
                        ))}
                      </React.Fragment>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        ))
      )}
    </div>
  );
}

function CountRow({
  item,
  value,
  onChange,
}: {
  item: InventoryItem;
  value: string;
  onChange: (v: string) => void;
}) {
  const { number } = useFormatters();
  const system = Number(item.current_stock);
  const diff = value.trim() !== "" && Number.isFinite(Number(value)) ? Number(value) - system : null;

  return (
    <tr className="border-b border-border/60 last:border-0">
      <td className="py-1.5 pr-3 font-medium">{item.name}</td>
      <td className="py-1.5 pr-3 text-muted-foreground">{item.unit_size ?? "—"}</td>
      <td className="py-1.5 pr-3 text-muted-foreground">{item.vintage ?? "—"}</td>
      <td className="py-1.5 pr-3 text-muted-foreground">{item.unit}</td>
      <td className="py-1.5 pr-3 text-right tabular-nums">{number(system)}</td>
      <td className="py-1.5 pr-3">
        <Input
          type="number"
          inputMode="decimal"
          aria-label={`${item.name} physical count`}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={String(system)}
          className="h-8 w-24"
        />
      </td>
      <td
        className={cn(
          "py-1.5 text-right tabular-nums",
          diff != null && diff !== 0 && (diff > 0 ? "text-emerald-600" : "text-destructive"),
        )}
      >
        {diff == null ? "—" : `${diff > 0 ? "+" : ""}${number(diff)}`}
      </td>
    </tr>
  );
}

function Pager({
  page,
  lastPage,
  onChange,
}: {
  page: number;
  lastPage: number;
  onChange: (p: number) => void;
}) {
  if (lastPage <= 1) return null;
  return (
    <div className="flex items-center gap-1 text-sm">
      <Button variant="outline" size="icon" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        <ChevronLeft className="size-4" />
      </Button>
      <span className="tabular-nums text-muted-foreground">
        {page}/{lastPage}
      </span>
      <Button
        variant="outline"
        size="icon"
        disabled={page >= lastPage}
        onClick={() => onChange(page + 1)}
      >
        <ChevronRight className="size-4" />
      </Button>
    </div>
  );
}

function CheckHistory() {
  const { t } = useTranslation();
  const { date, number } = useFormatters();
  const [page, setPage] = React.useState(1);
  const [openId, setOpenId] = React.useState<string | null>(null);
  const { data, isLoading } = useInventoryChecks(page);
  const checks = data?.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        {isLoading ? (
          <div className="flex justify-center py-10">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : checks.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t("inventory.check.history.empty")}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("inventory.check.history.date")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inventory.check.history.by")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("inventory.check.history.counted")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("inventory.check.history.adjusted")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("inventory.check.history.netDiff")}</th>
                  <th className="py-2" />
                </tr>
              </thead>
              <tbody>
                {checks.map((c) => (
                  <tr key={c.id} className="border-b border-border/60 last:border-0">
                    <td className="py-2 pr-3">{c.created_at ? date(c.created_at) : "—"}</td>
                    <td className="py-2 pr-3 text-muted-foreground">{c.performed_by ?? "—"}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{number(c.items_counted)}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{number(c.items_adjusted)}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{c.net_difference}</td>
                    <td className="py-2 text-right">
                      <button
                        type="button"
                        onClick={() => setOpenId(c.id)}
                        className="text-sm font-medium text-primary hover:underline"
                      >
                        {t("inventory.check.history.view")}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager page={page} lastPage={data?.meta?.last_page ?? 1} onChange={setPage} />
      </CardContent>

      {openId && <CheckLinesDialog id={openId} onClose={() => setOpenId(null)} />}
    </Card>
  );
}

function CheckLinesDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const { t } = useTranslation();
  const { data, isLoading } = useInventoryCheck(id);

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("inventory.check.history.linesTitle")}>
      {isLoading || !data ? (
        <div className="flex justify-center py-6">
          <Spinner className="size-5 text-muted-foreground" />
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <tr>
                <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.product")}</th>
                <th className="py-2 pr-3 font-medium">{t("inventory.check.cols.sku")}</th>
                <th className="py-2 pr-3 text-right font-medium">{t("inventory.check.cols.system")}</th>
                <th className="py-2 pr-3 text-right font-medium">{t("inventory.check.cols.physical")}</th>
                <th className="py-2 text-right font-medium">{t("inventory.check.cols.difference")}</th>
              </tr>
            </thead>
            <tbody>
              {data.lines.map((l, i) => (
                <tr key={i} className="border-b border-border/60 last:border-0">
                  <td className="py-1.5 pr-3 font-medium">{l.name}</td>
                  <td className="py-1.5 pr-3 text-muted-foreground">{l.sku}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{l.system_count}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{l.physical_count}</td>
                  <td
                    className={cn(
                      "py-1.5 text-right tabular-nums",
                      Number(l.difference) > 0 ? "text-emerald-600" : "text-destructive",
                    )}
                  >
                    {l.difference}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Dialog>
  );
}

interface CatGroup {
  label: string | null;
  items: InventoryItem[];
}
interface CatSection {
  category: string;
  groups: CatGroup[];
}

/** Group a page of items by category, then by "group · subcategory". */
function groupByCategory(items: InventoryItem[]): CatSection[] {
  const byCat = new Map<string, Map<string, InventoryItem[]>>();
  for (const item of items) {
    const cat = item.category;
    const label = [item.group, item.subcategory].filter(Boolean).join(" · ") || "";
    if (!byCat.has(cat)) byCat.set(cat, new Map());
    const groups = byCat.get(cat)!;
    if (!groups.has(label)) groups.set(label, []);
    groups.get(label)!.push(item);
  }
  return [...byCat.entries()].map(([category, groups]) => ({
    category,
    groups: [...groups.entries()].map(([label, its]) => ({ label: label || null, items: its })),
  }));
}
