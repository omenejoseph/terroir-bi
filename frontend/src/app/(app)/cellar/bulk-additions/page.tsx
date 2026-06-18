"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useTranslation } from "@/i18n/context";
import { useBulkAdditions, useEnologicalProducts, useWineLots } from "@/hooks/use-cellar";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

export default function BulkAdditionsPage() {
  const { t } = useTranslation();
  const products = useEnologicalProducts({ active_only: true });
  const lots = useWineLots({ exclude_bottled: true, per_page: 200 });
  const bulk = useBulkAdditions();

  const [productId, setProductId] = React.useState("");
  const [quantity, setQuantity] = React.useState("");
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [error, setError] = React.useState<string | null>(null);
  const [done, setDone] = React.useState(false);

  const product = (products.data ?? []).find((p) => p.id === productId);

  function toggle(id: string) {
    setSelected((s) => {
      const next = new Set(s);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setDone(false);
    if (!product || selected.size === 0 || Number(quantity) <= 0) return;
    try {
      await bulk.mutateAsync({
        name: product.name,
        unit: product.unit,
        category: product.category,
        enological_product_id: product.id,
        lots: Array.from(selected).map((id) => ({ wine_lot_id: id, quantity: Number(quantity) })),
      });
      setSelected(new Set());
      setQuantity("");
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-6">
      <CellarPageHeader title={t("cellar.bulkAdditionsPage.title")} subtitle={t("cellar.bulkAdditionsPage.subtitle")} />
      <form onSubmit={submit} className="space-y-4">
        <Card>
          <CardContent className="flex flex-wrap items-end gap-3 p-4">
            <div className="min-w-48">
              <Label>{t("cellar.bulkAdditionsPage.product")}</Label>
              <Select value={productId} onChange={(e) => setProductId(e.target.value)} required>
                <option value="">—</option>
                {(products.data ?? []).map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} ({p.unit})
                  </option>
                ))}
              </Select>
            </div>
            <div className="w-40">
              <Label>{t("cellar.bulkAdditionsPage.quantity")}</Label>
              <Input type="number" step="0.001" value={quantity} onChange={(e) => setQuantity(e.target.value)} required />
            </div>
            <Button type="submit" disabled={bulk.isPending || !product || selected.size === 0}>
              {t("cellar.bulkAdditionsPage.apply")} ({selected.size})
            </Button>
            {done && <span className="text-sm text-green-600">{t("cellar.bulkAdditionsPage.done")}</span>}
            {error && <span className="text-sm text-destructive">{error}</span>}
          </CardContent>
        </Card>

        <div>
          <Label>{t("cellar.bulkAdditionsPage.selectLots")}</Label>
          {lots.isLoading ? (
            <div className="flex h-24 items-center justify-center">
              <Spinner />
            </div>
          ) : (
            <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {(lots.data?.data ?? []).map((lot) => (
                <label key={lot.id} className="flex cursor-pointer items-center gap-2 rounded-md border border-border p-2 text-sm">
                  <input type="checkbox" checked={selected.has(lot.id)} onChange={() => toggle(lot.id)} />
                  <span className="truncate">
                    {lot.name} <span className="text-muted-foreground">· {lot.lot_number}</span>
                  </span>
                </label>
              ))}
            </div>
          )}
        </div>
      </form>
    </div>
  );
}
