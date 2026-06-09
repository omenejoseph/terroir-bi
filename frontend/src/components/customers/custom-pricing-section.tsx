"use client";

import * as React from "react";
import { Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useCustomerCustomPrices,
  useRemoveCustomPrice,
  useSetCustomPrice,
} from "@/hooks/use-customers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { majorToMinor } from "@/lib/money";
import type { InventoryItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";
import { InventoryItemPicker } from "@/components/inventory/inventory-item-picker";

export function CustomPricingSection({
  customerId,
  canManage,
}: {
  customerId: string;
  canManage: boolean;
}) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const confirm = useConfirm();
  const listQ = useCustomerCustomPrices(customerId);
  const setPrice = useSetCustomPrice(customerId);
  const removePrice = useRemoveCustomPrice(customerId);

  const [item, setItem] = React.useState<InventoryItem | null>(null);
  const [price, setPriceInput] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  async function add(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    const minor = majorToMinor(price);
    if (!item || minor === null) {
      setError(t("customers.pricing.invalid"));
      return;
    }
    try {
      await setPrice.mutateAsync({ itemId: item.id, minor });
      setItem(null);
      setPriceInput("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("customers.pricing.errorGeneric"));
    }
  }

  async function remove(itemId: string, name: string | null) {
    const ok = await confirm({
      title: t("customers.pricing.removeTitle"),
      description: t("customers.pricing.removeBody", { name: name ?? "" }),
      confirmLabel: t("customers.pricing.remove"),
      tone: "danger",
    });
    if (ok) await removePrice.mutateAsync(itemId);
  }

  const rows = listQ.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <p className="text-sm text-muted-foreground">{t("customers.pricing.intro")}</p>

        {listQ.isLoading ? (
          <div className="flex justify-center py-8">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("customers.pricing.empty")}</p>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {rows.map((row) => (
              <li key={row.inventory_item_id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{row.name ?? row.inventory_item_id}</p>
                  {row.sku && <p className="truncate text-xs text-muted-foreground">{row.sku}</p>}
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-sm font-medium tabular-nums">{moneyObject(row.price)}</span>
                  {canManage && (
                    <button
                      type="button"
                      onClick={() => remove(row.inventory_item_id, row.name)}
                      aria-label={t("customers.pricing.remove")}
                      className="text-muted-foreground transition-colors hover:text-destructive"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {canManage && (
          <form onSubmit={add} className="space-y-3 border-t border-border pt-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
              <div className="space-y-1.5">
                <Label htmlFor="cp-item">{t("customers.pricing.product")}</Label>
                <InventoryItemPicker
                  id="cp-item"
                  valueLabel={item?.name}
                  onChange={setItem}
                  placeholder={t("customers.pricing.pickProduct")}
                  searchPlaceholder={t("customers.pricing.searchProduct")}
                  emptyLabel={t("customers.pricing.noProducts")}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="cp-price">{t("customers.pricing.priceLabel")}</Label>
                <Input
                  id="cp-price"
                  type="number"
                  min={0}
                  step="0.01"
                  value={price}
                  onChange={(e) => setPriceInput(e.target.value)}
                  className="w-32"
                />
              </div>
              <Button type="submit" disabled={setPrice.isPending}>
                {setPrice.isPending && <Spinner />}
                {t("customers.pricing.save")}
              </Button>
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
          </form>
        )}
      </CardContent>
    </Card>
  );
}
