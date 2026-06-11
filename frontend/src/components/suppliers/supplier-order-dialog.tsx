"use client";

import * as React from "react";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { majorToMinor } from "@/lib/money";
import { useCreateSupplierOrder } from "@/hooks/use-suppliers";
import { useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import type { SupplierOrderInput } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

interface LineState {
  description: string;
  quantity: string;
  unit: string;
  unit_price: string;
}

const EMPTY_LINE: LineState = { description: "", quantity: "1", unit: "", unit_price: "" };

export function SupplierOrderDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: () => void;
}) {
  const { t } = useTranslation();
  const create = useCreateSupplierOrder();
  const suppliersQ = useSuppliers();
  const suppliers = suppliersQ.data?.data ?? [];

  const [supplierId, setSupplierId] = React.useState("");
  const [expectedAt, setExpectedAt] = React.useState("");
  const [notes, setNotes] = React.useState("");
  const [lines, setLines] = React.useState<LineState[]>([{ ...EMPTY_LINE }]);
  const [formError, setFormError] = React.useState<string | null>(null);

  function setLine(index: number, patch: Partial<LineState>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  function addLine() {
    setLines((prev) => [...prev, { ...EMPTY_LINE }]);
  }

  function removeLine(index: number) {
    setLines((prev) => (prev.length === 1 ? prev : prev.filter((_, i) => i !== index)));
  }

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setFormError(null);
    const input: SupplierOrderInput = {
      supplier_id: supplierId,
      expected_at: expectedAt || null,
      notes: notes.trim() || null,
      items: lines
        .filter((l) => l.description.trim())
        .map((l) => ({
          description: l.description.trim(),
          quantity: Number(l.quantity),
          unit: l.unit.trim() || null,
          unit_price: majorToMinor(l.unit_price) ?? 0, // major units (€) → minor
        })),
    };
    try {
      await create.mutateAsync(input);
      onCreated();
      onOpenChange(false);
      setSupplierId("");
      setExpectedAt("");
      setNotes("");
      setLines([{ ...EMPTY_LINE }]);
    } catch (err) {
      setFormError(
        err instanceof ApiError ? err.message : t("supplierOrders.form.errorGeneric"),
      );
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange} title={t("supplierOrders.add")} className="max-w-2xl">
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="po_supplier">{t("supplierOrders.form.supplier")}</Label>
          <Select
            id="po_supplier"
            value={supplierId}
            onChange={(e) => setSupplierId(e.target.value)}
            required
          >
            <option value="">{t("supplierOrders.form.selectSupplier")}</option>
            {suppliers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.company_name}
              </option>
            ))}
          </Select>
        </div>

        <div className="space-y-2">
          <Label htmlFor="po_expected">{t("supplierOrders.form.expectedAt")}</Label>
          <Input
            id="po_expected"
            type="date"
            value={expectedAt}
            onChange={(e) => setExpectedAt(e.target.value)}
          />
        </div>

        <div className="space-y-2">
          <Label>{t("supplierOrders.form.items")}</Label>
          <div className="space-y-2">
            {lines.map((line, index) => (
              <div key={index} className="grid grid-cols-1 gap-2 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-center">
                <Input
                  aria-label={`${t("supplierOrders.form.description")} ${index + 1}`}
                  value={line.description}
                  onChange={(e) => setLine(index, { description: e.target.value })}
                  placeholder={t("supplierOrders.form.description")}
                />
                <Input
                  aria-label={`${t("supplierOrders.form.quantity")} ${index + 1}`}
                  type="number"
                  min={0}
                  step="any"
                  value={line.quantity}
                  onChange={(e) => setLine(index, { quantity: e.target.value })}
                  placeholder={t("supplierOrders.form.quantity")}
                />
                <Input
                  aria-label={`${t("supplierOrders.form.unitPrice")} ${index + 1}`}
                  type="number"
                  min={0}
                  step="0.01"
                  value={line.unit_price}
                  onChange={(e) => setLine(index, { unit_price: e.target.value })}
                  placeholder={t("supplierOrders.form.unitPrice")}
                />
                <button
                  type="button"
                  aria-label={t("supplierOrders.delete")}
                  onClick={() => removeLine(index)}
                  className="justify-self-start text-muted-foreground hover:text-destructive sm:justify-self-center"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            ))}
          </div>
          <Button type="button" variant="outline" size="sm" onClick={addLine}>
            <Plus className="size-4" />
            {t("supplierOrders.form.addLine")}
          </Button>
        </div>

        <div className="space-y-2">
          <Label htmlFor="po_notes">{t("supplierOrders.form.notes")}</Label>
          <Input id="po_notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {formError && (
          <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
        )}

        <div className="flex justify-end gap-2 border-t border-border pt-4">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t("supplierOrders.form.cancel")}
          </Button>
          <Button type="submit" disabled={create.isPending || !supplierId}>
            {create.isPending && <Spinner />}
            {t("supplierOrders.form.create")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
