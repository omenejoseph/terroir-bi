"use client";

import * as React from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import {
  useAddPriceItem,
  useDeletePriceItem,
  useSupplier,
  useUpdatePriceItem,
} from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { majorToMinor, minorToMajorInput } from "@/lib/money";
import { useTranslation } from "@/i18n/context";
import { INVENTORY_UNITS, type SupplierPriceItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

export function SupplierPriceListSection({ supplierId }: { supplierId: string }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject } = useFormatters();
  const confirm = useConfirm();
  // Fetch the full supplier (the list endpoint only sends a count, not the items).
  const { data: supplier } = useSupplier(supplierId);
  const items = supplier?.price_items ?? [];
  const add = useAddPriceItem();
  const updateItem = useUpdatePriceItem();
  const remove = useDeletePriceItem();
  const canManage = can("suppliers.manage");

  const [showForm, setShowForm] = React.useState(false);
  const [editingId, setEditingId] = React.useState<string | null>(null);
  const [description, setDescription] = React.useState("");
  const [unitPrice, setUnitPrice] = React.useState("");
  const [unit, setUnit] = React.useState("");
  const saving = add.isPending || updateItem.isPending;

  // Show the friendly label for a standard unit; fall back to raw legacy text.
  const unitLabel = (u: string | null) =>
    u ? ((INVENTORY_UNITS as readonly string[]).includes(u) ? t(`inventory.add.unit.${u}`) : u) : "";

  function resetForm() {
    setDescription("");
    setUnitPrice("");
    setUnit("");
    setEditingId(null);
    setShowForm(false);
  }

  function startAdd() {
    setEditingId(null);
    setDescription("");
    setUnitPrice("");
    setUnit("");
    setShowForm(true);
  }

  function startEdit(item: SupplierPriceItem) {
    setEditingId(item.id);
    setDescription(item.description);
    setUnitPrice(minorToMajorInput(item.unit_price.minor));
    setUnit(item.unit ?? "");
    setShowForm(true);
  }

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    if (!description.trim() || unitPrice.trim() === "") return;
    const input = {
      description: description.trim(),
      unit_price: majorToMinor(unitPrice) ?? 0, // major units (€) → minor
      unit: unit.trim() || null,
    };
    if (editingId) {
      await updateItem.mutateAsync({ id: supplierId, priceItemId: editingId, input });
    } else {
      await add.mutateAsync({ id: supplierId, input });
    }
    resetForm();
  }

  async function handleRemove(item: SupplierPriceItem) {
    const ok = await confirm({
      title: t("suppliers.priceList.deleteTitle"),
      description: t("suppliers.priceList.deleteBody", { description: item.description }),
      confirmLabel: t("suppliers.delete.action"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync({ id: supplierId, priceItemId: item.id });
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-sm font-semibold">{t("suppliers.priceList.title")}</h2>
          {canManage && !showForm && (
            <Button type="button" variant="outline" size="sm" onClick={startAdd}>
              <Plus className="size-4" />
              {t("suppliers.priceList.add")}
            </Button>
          )}
        </div>

        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("suppliers.priceList.empty")}</p>
        ) : (
          <ul className="divide-y divide-border">
            {items.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium">{item.description}</p>
                  <p className="text-xs text-muted-foreground">{unitLabel(item.unit)}</p>
                </div>
                <div className="flex shrink-0 items-center gap-3">
                  <span className="tabular-nums">{moneyObject(item.unit_price)}</span>
                  {canManage && (
                    <button
                      type="button"
                      aria-label={t("suppliers.priceList.edit", { description: item.description })}
                      onClick={() => startEdit(item)}
                      className="text-muted-foreground hover:text-foreground"
                    >
                      <Pencil className="size-4" />
                    </button>
                  )}
                  {canManage && (
                    <button
                      type="button"
                      aria-label={t("suppliers.priceList.remove")}
                      onClick={() => handleRemove(item)}
                      className="text-muted-foreground hover:text-destructive"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {canManage && showForm && (
          <form onSubmit={handleSubmit} className="space-y-3 border-t border-border pt-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr_1fr] sm:items-end">
              <div className="space-y-1">
                <Label htmlFor="pi_description" className="text-xs">
                  {t("suppliers.priceList.description")}
                </Label>
                <Input
                  id="pi_description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  autoFocus
                />
              </div>
              <div className="space-y-1">
                <Label htmlFor="pi_unit_price" className="text-xs">
                  {t("suppliers.priceList.unitPrice")}
                </Label>
                <Input
                  id="pi_unit_price"
                  type="number"
                  min={0}
                  step="0.01"
                  value={unitPrice}
                  onChange={(e) => setUnitPrice(e.target.value)}
                />
              </div>
              <div className="space-y-1">
                <Label htmlFor="pi_unit" className="text-xs">
                  {t("suppliers.priceList.unit")}
                </Label>
                <Select id="pi_unit" value={unit} onChange={(e) => setUnit(e.target.value)}>
                  <option value="">—</option>
                  {INVENTORY_UNITS.map((u) => (
                    <option key={u} value={u}>
                      {t(`inventory.add.unit.${u}`)}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={resetForm}>
                {t("suppliers.priceList.cancel")}
              </Button>
              <Button
                type="submit"
                disabled={saving || !description.trim() || unitPrice.trim() === ""}
              >
                {saving && <Spinner />}
                {t("suppliers.priceList.submit")}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
