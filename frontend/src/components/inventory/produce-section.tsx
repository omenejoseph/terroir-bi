"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useProduce, useRecipe } from "@/hooks/use-inventory";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

/**
 * Production tab: shows the bill of materials scaled to the quantity to produce
 * (with each input's available stock) and runs the production.
 */
export function ProduceSection({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { number } = useFormatters();
  const confirm = useConfirm();
  const produce = useProduce();
  const recipeQ = useRecipe(item.id);

  const [quantity, setQuantity] = React.useState("1");
  const [error, setError] = React.useState<string | null>(null);
  const [done, setDone] = React.useState<string | null>(null);

  const lines = recipeQ.data ?? [];
  const qtyNum = Number(quantity.trim());
  const multiplier = Number.isFinite(qtyNum) && qtyNum > 0 ? qtyNum : 0;

  async function run() {
    if (multiplier <= 0) return;
    setError(null);
    setDone(null);
    const ok = await confirm({
      title: t("inventory.produce.confirmTitle"),
      description: t("inventory.produce.confirmBody", {
        quantity: multiplier,
        unit: item.unit,
        name: item.name,
      }),
    });
    if (!ok) return;
    try {
      await produce.mutateAsync({ id: item.id, input: { display_quantity: multiplier } });
      setDone(t("inventory.produce.success", { quantity: multiplier, unit: item.unit }));
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (err.fieldError("quantity") ?? err.message)
          : t("inventory.produce.errorGeneric"),
      );
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div>
          <h3 className="text-sm font-semibold">{t("inventory.produce.title")}</h3>
          <p className="text-xs text-muted-foreground">{t("inventory.produce.subtitle")}</p>
        </div>

        {recipeQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : lines.length === 0 ? (
          <p className="py-2 text-sm text-muted-foreground">{t("inventory.produce.noRecipe")}</p>
        ) : (
          <>
            <p className="text-sm font-medium">
              {t("inventory.produce.materialsFor", {
                quantity: number(multiplier),
                unit: item.unit,
              })}
            </p>
            <ul className="divide-y divide-border rounded-md border border-border">
              {lines.map((l) => {
                const need = Number(l.quantity) * multiplier;
                const have = l.input_stock != null ? Number(l.input_stock) : null;
                const short = have != null && Number.isFinite(need) && have < need;
                return (
                  <li
                    key={l.input_id ?? l.input_name}
                    className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                  >
                    <p className="min-w-0 truncate font-medium">
                      {l.input_name}
                      {l.input_group && (
                        <span className="text-muted-foreground"> ({l.input_group})</span>
                      )}
                    </p>
                    <div className="shrink-0 text-right tabular-nums">
                      <span className={short ? "font-medium text-destructive" : ""}>
                        {number(need)} {l.input_unit}
                      </span>
                      {have != null && (
                        <span className="block text-xs text-muted-foreground">
                          {t("inventory.produce.have", { count: number(have) })}
                        </span>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>

            {canManage && (
              <div className="flex items-end gap-2">
                <div className="flex-1 space-y-1">
                  <Label htmlFor="produce-qty">
                    {t("inventory.produce.quantityLabel", { unit: item.unit })}
                  </Label>
                  <Input
                    id="produce-qty"
                    type="number"
                    min={0}
                    step="any"
                    value={quantity}
                    onChange={(e) => setQuantity(e.target.value)}
                  />
                </div>
                <Button type="button" onClick={run} disabled={produce.isPending || multiplier <= 0}>
                  {produce.isPending && <Spinner />}
                  {t("inventory.produce.action")}
                </Button>
              </div>
            )}
          </>
        )}

        {error && <p className="text-sm text-destructive">{error}</p>}
        {done && <p className="text-sm text-success">{done}</p>}
      </CardContent>
    </Card>
  );
}
