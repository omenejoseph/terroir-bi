"use client";

import * as React from "react";
import { useParams } from "next/navigation";
import { CheckCircle2, Wine } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { usePublicCatalog, usePlacePublicOrder } from "@/hooks/use-public-order";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { APP_NAME } from "@/lib/config";
import type { PublicCatalogProduct } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";

export default function PublicOrderPage() {
  const params = useParams<{ token: string }>();
  const token = params?.token;
  const { t, locale } = useTranslation();
  const { moneyObject } = useFormatters();

  const catalogQ = usePublicCatalog(token);
  const place = usePlacePublicOrder(token ?? "");

  const [qty, setQty] = React.useState<Record<string, string>>({});
  const [notes, setNotes] = React.useState("");
  const [placed, setPlaced] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  const catalog = catalogQ.data;
  const showPrices = catalog ? !catalog.customer.hide_prices : false;
  const currency = catalog?.products.find((p) => p.price)?.price?.currency ?? "EUR";

  const lines = React.useMemo(
    () =>
      (catalog?.products ?? [])
        .map((p) => ({ product: p, quantity: Number(qty[p.id] ?? "") || 0 }))
        .filter((l) => l.quantity > 0),
    [catalog, qty],
  );
  const totalMinor = lines.reduce((sum, l) => sum + (l.product.price?.minor ?? 0) * l.quantity, 0);
  const fmtMoney = (minor: number) =>
    new Intl.NumberFormat(locale, { style: "currency", currency }).format(minor / 100);

  async function submit() {
    setError(null);
    if (lines.length === 0) {
      setError(t("publicOrder.empty"));
      return;
    }
    try {
      const result = await place.mutateAsync({
        items: lines.map((l) => ({
          inventory_item_id: l.product.id,
          quantity: l.quantity,
          unit_type: l.product.unit,
        })),
        notes: notes.trim() || null,
      });
      setPlaced(result.order_number);
    } catch (err) {
      if (err instanceof ApiError && err.status === 429) setError(t("publicOrder.rateLimited"));
      else setError(err instanceof ApiError ? err.message : t("publicOrder.errorGeneric"));
    }
  }

  return (
    <div className="min-h-screen bg-muted/30 px-4 py-10">
      <div className="mx-auto max-w-2xl space-y-6">
        <header className="flex items-center gap-2">
          <Wine className="size-5 text-primary" />
          <span className="text-sm font-semibold text-muted-foreground">{APP_NAME}</span>
        </header>

        {catalogQ.isLoading ? (
          <div className="flex justify-center py-20">
            <Spinner className="size-6 text-muted-foreground" />
          </div>
        ) : catalogQ.isError || !catalog ? (
          <Card>
            <CardContent className="py-16 text-center text-sm text-muted-foreground">
              {t("publicOrder.invalid")}
            </CardContent>
          </Card>
        ) : placed ? (
          <Card>
            <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
              <CheckCircle2 className="size-10 text-emerald-500" />
              <h1 className="text-lg font-semibold">{t("publicOrder.thanks")}</h1>
              <p className="text-sm text-muted-foreground">
                {t("publicOrder.placed", { order: placed })}
              </p>
            </CardContent>
          </Card>
        ) : (
          <>
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">{t("publicOrder.title")}</h1>
              <p className="text-sm text-muted-foreground">{catalog.customer.company_name}</p>
            </div>

            <Card>
              <CardContent className="divide-y divide-border p-0">
                {catalog.products.length === 0 ? (
                  <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                    {t("publicOrder.noProducts")}
                  </p>
                ) : (
                  catalog.products.map((product: PublicCatalogProduct) => (
                    <div key={product.id} className="flex items-center justify-between gap-3 px-4 py-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{product.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                          {t(`inventory.add.salesUnit.${product.unit}`)}
                          {showPrices && product.price ? ` · ${moneyObject(product.price)}` : ""}
                        </p>
                      </div>
                      <Input
                        type="number"
                        min={0}
                        inputMode="numeric"
                        value={qty[product.id] ?? ""}
                        onChange={(e) => setQty((q) => ({ ...q, [product.id]: e.target.value }))}
                        aria-label={t("publicOrder.quantityFor", { name: product.name })}
                        className="w-20"
                      />
                    </div>
                  ))
                )}
              </CardContent>
            </Card>

            <div className="space-y-2">
              <label htmlFor="po-notes" className="text-sm font-medium">
                {t("publicOrder.notes")}
              </label>
              <textarea
                id="po-notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={3}
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>

            {error && (
              <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
            )}

            <div className="flex items-center justify-between gap-3">
              {showPrices ? (
                <p className="text-sm">
                  {t("publicOrder.total")}{" "}
                  <span className="font-semibold tabular-nums">{fmtMoney(totalMinor)}</span>
                </p>
              ) : (
                <span />
              )}
              <Button type="button" onClick={submit} disabled={place.isPending}>
                {place.isPending && <Spinner />}
                {t("publicOrder.submit")}
              </Button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
