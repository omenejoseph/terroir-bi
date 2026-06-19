"use client";

import * as React from "react";
import { Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useCostCategories, useCreateCost, useUpdateCost, useUpdateCostStatus } from "@/hooks/use-costs";
import { useSupplier, useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import { majorToMinor, minorToMajorInput } from "@/lib/money";
import { COST_STATUSES, PAYMENT_METHODS, type Cost, type CostInput, type CostStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/** A cost line being edited in the form (amounts in major units / €). */
type Line = {
  key: string;
  description: string;
  quantity: string;
  unitPrice: string;
  inventoryItemId: string | null;
  category: string | null;
};

let lineSeq = 0;
function blankLine(): Line {
  lineSeq += 1;
  return { key: `l${lineSeq}`, description: "", quantity: "1", unitPrice: "", inventoryItemId: null, category: null };
}

/** Minor-unit total of a line, matching the backend (round(unit_price × qty)). */
function lineTotalMinor(line: Line): number {
  const unit = majorToMinor(line.unitPrice) ?? 0;
  const qty = Number.parseFloat(line.quantity);
  return Math.round(unit * (Number.isFinite(qty) ? qty : 0));
}

export function CostForm({
  cost,
  onSaved,
  onCancel,
}: {
  cost?: Cost;
  onSaved: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const create = useCreateCost();
  const update = useUpdateCost();
  const updateStatus = useUpdateCostStatus();
  const categoriesQ = useCostCategories();
  const canViewSuppliers = can("suppliers.view");
  const editing = cost !== undefined;

  const [costDate, setCostDate] = React.useState(cost ? cost.date.slice(0, 10) : today());
  const [totalAmount, setTotalAmount] = React.useState(cost ? minorToMajorInput(cost.total_amount.minor) : "");
  const [vatAmount, setVatAmount] = React.useState(cost?.vat_amount ? minorToMajorInput(cost.vat_amount.minor) : "");
  const [category, setCategory] = React.useState(cost?.category ?? "");
  const [description, setDescription] = React.useState(cost?.description ?? "");
  const [reference, setReference] = React.useState(cost?.reference ?? "");
  const [status, setStatus] = React.useState<CostStatus>(cost?.status ?? "PENDING");
  const [paymentMethod, setPaymentMethod] = React.useState(cost?.payment_method ?? "");
  const [supplierId, setSupplierId] = React.useState(cost?.supplier?.id ?? "");
  const [notes, setNotes] = React.useState(cost?.notes ?? "");
  // Seed the builder with the cost's existing line items when editing.
  const [lines, setLines] = React.useState<Line[]>(() =>
    (cost?.items ?? []).map((it) => ({
      key: `existing-${it.id}`,
      description: it.description,
      quantity: String(Number(it.quantity)),
      unitPrice: minorToMajorInput(it.unit_price.minor),
      inventoryItemId: it.inventory_item_id,
      category: it.category,
    })),
  );
  const [formError, setFormError] = React.useState<string | null>(null);

  // Line items drive the total whenever any exist (create or edit).
  const usingLines = lines.length > 0;
  const linesTotalMinor = React.useMemo(() => lines.reduce((s, l) => s + lineTotalMinor(l), 0), [lines]);

  const pending = create.isPending || update.isPending || updateStatus.isPending;

  function updateLine(key: string, patch: Partial<Line>) {
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  }

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setFormError(null);
    try {
      const itemsPayload = lines
        .filter((l) => l.description.trim() !== "")
        .map((l) => ({
          description: l.description.trim(),
          unit_price: majorToMinor(l.unitPrice) ?? 0,
          quantity: Number.parseFloat(l.quantity) || 0,
          ...(l.inventoryItemId ? { inventory_item_id: l.inventoryItemId } : {}),
          ...(l.category ? { category: l.category } : {}),
        }));

      if (editing) {
        // Update sends explicit nulls so cleared fields are persisted; status
        // goes through its dedicated endpoint (the update route ignores it).
        // When line items are present they're sent and replace the cost's lines.
        await update.mutateAsync({
          id: cost.id,
          input: {
            total_amount: usingLines ? linesTotalMinor : (majorToMinor(totalAmount) ?? 0),
            vat_amount: vatAmount.trim() ? majorToMinor(vatAmount) : null,
            category: category.trim(),
            date: costDate || undefined,
            description: description.trim() || null,
            reference: reference.trim() || null,
            payment_method: paymentMethod || null,
            supplier_id: supplierId || null,
            notes: notes.trim() || null,
            ...(usingLines ? { items: itemsPayload } : {}),
          },
        });
        if (status !== cost.status) {
          await updateStatus.mutateAsync({ id: cost.id, status });
        }
      } else {
        const total = usingLines ? linesTotalMinor : (majorToMinor(totalAmount) ?? 0);
        const input: CostInput = {
          total_amount: total, // amounts entered in major units (€)
          category: category.trim(),
          ...(vatAmount.trim() ? { vat_amount: majorToMinor(vatAmount) } : {}),
          ...(costDate ? { date: costDate } : {}),
          ...(description.trim() ? { description: description.trim() } : {}),
          ...(reference.trim() ? { reference: reference.trim() } : {}),
          status,
          ...(paymentMethod ? { payment_method: paymentMethod } : {}),
          ...(supplierId ? { supplier_id: supplierId } : {}),
          ...(notes.trim() ? { notes: notes.trim() } : {}),
          ...(usingLines ? { items: itemsPayload } : {}),
        };
        await create.mutateAsync(input);
      }
      onSaved();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("costs.form.errorGeneric"));
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {/* Supplier first — it drives the price-list line picker below. */}
      {canViewSuppliers && <SupplierSelect value={supplierId} onChange={setSupplierId} />}

      {/* Line items lead the form; the total is derived from them, shown below. */}
      <LineItemsBuilder
        lines={lines}
        setLines={setLines}
        updateLine={updateLine}
        supplierId={supplierId}
      />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="cost_date">{t("costs.form.date")}</Label>
          <Input id="cost_date" type="date" value={costDate} onChange={(e) => setCostDate(e.target.value)} required />
        </div>
        <div className="space-y-2">
          <Label htmlFor="cost_status">{t("costs.form.status")}</Label>
          <Select id="cost_status" value={status} onChange={(e) => setStatus(e.target.value as CostStatus)}>
            {COST_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`costs.status.${s}`)}
              </option>
            ))}
          </Select>
        </div>

        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="cost_category">{t("costs.form.category")}</Label>
          <Combobox
            id="cost_category"
            value={category}
            onChange={setCategory}
            options={categoriesQ.data ?? []}
            placeholder={t("costs.form.categoryPlaceholder")}
            createLabel={(q) => t("costs.form.createCategory", { name: q })}
            emptyLabel={t("costs.form.categoryEmpty")}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="cost_payment">{t("costs.form.paymentMethod")}</Label>
          <Select id="cost_payment" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
            <option value="">{t("costs.form.selectPaymentMethod")}</option>
            {PAYMENT_METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`costs.paymentMethods.${m}`)}
              </option>
            ))}
          </Select>
        </div>
      </div>

      <div className="space-y-2">
        <Label htmlFor="cost_description">{t("costs.form.description")}</Label>
        <Input id="cost_description" value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="cost_reference">{t("costs.form.reference")}</Label>
        <Input id="cost_reference" value={reference} onChange={(e) => setReference(e.target.value)} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="cost_notes">{t("costs.form.notes")}</Label>
        <Input id="cost_notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>

      {/* VAT (PDV) — optional; drives the incl-PDV / Net breakdown on the cost detail. */}
      <div className="space-y-2">
        <Label htmlFor="cost_vat">{t("costs.form.vatAmount")}</Label>
        <div className="flex items-center gap-2">
          <Input
            id="cost_vat"
            type="number"
            min={0}
            step="0.01"
            value={vatAmount}
            placeholder="0.00"
            onChange={(e) => setVatAmount(e.target.value)}
          />
          <span className="shrink-0 rounded-md border border-input px-3 py-2 text-sm text-muted-foreground">
            {t("costs.form.currency")}
          </span>
        </div>
      </div>

      {/* Total — derived from the line items (text) or entered directly when there are none. */}
      {usingLines ? (
        <div className="flex items-center justify-between border-t border-border pt-4">
          <span className="text-sm font-medium">{t("costs.form.totalAmount")}</span>
          <span className="text-lg font-bold tabular-nums">
            {(linesTotalMinor / 100).toFixed(2)} {t("costs.form.currency")}
          </span>
        </div>
      ) : (
        <div className="space-y-2">
          <Label htmlFor="cost_total">{t("costs.form.totalAmount")}</Label>
          <div className="flex items-center gap-2">
            <Input
              id="cost_total"
              type="number"
              min={0}
              step="0.01"
              value={totalAmount}
              onChange={(e) => setTotalAmount(e.target.value)}
              required
            />
            <span className="shrink-0 rounded-md border border-input px-3 py-2 text-sm text-muted-foreground">
              {t("costs.form.currency")}
            </span>
          </div>
        </div>
      )}

      {formError && (
        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
      )}

      <div className="flex justify-end gap-2 border-t border-border pt-4">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("costs.form.cancel")}
        </Button>
        <Button type="submit" disabled={pending}>
          {pending && <Spinner />}
          {editing ? t("costs.form.save") : t("costs.form.create")}
        </Button>
      </div>
    </form>
  );
}

/** Manual + supplier-price-list line items. Total is summed from these. */
function LineItemsBuilder({
  lines,
  setLines,
  updateLine,
  supplierId,
}: {
  lines: Line[];
  setLines: React.Dispatch<React.SetStateAction<Line[]>>;
  updateLine: (key: string, patch: Partial<Line>) => void;
  supplierId: string;
}) {
  const { t } = useTranslation();
  const supplierQ = useSupplier(supplierId || undefined);
  const priceItems = supplierQ.data?.price_items ?? [];

  function addFromPriceItem(id: string) {
    const item = priceItems.find((p) => p.id === id);
    if (!item) return;
    setLines((prev) => [
      ...prev,
      {
        ...blankLine(),
        description: item.description,
        unitPrice: minorToMajorInput(item.unit_price.minor),
        inventoryItemId: item.inventory_item_id,
      },
    ]);
  }

  return (
    <div className="space-y-2 rounded-lg border border-border/60 p-3">
      <div className="flex items-center justify-between">
        <Label>{t("costs.form.lineItems")}</Label>
        <div className="flex items-center gap-2">
          {supplierId && priceItems.length > 0 && (
            <Select
              aria-label={t("costs.form.fromPriceList")}
              value=""
              onChange={(e) => {
                if (e.target.value) addFromPriceItem(e.target.value);
              }}
              className="h-9 w-56"
            >
              <option value="">{t("costs.form.selectPriceItem")}</option>
              {priceItems.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.description} · {(p.unit_price.minor / 100).toFixed(2)}
                </option>
              ))}
            </Select>
          )}
          <Button type="button" variant="outline" size="sm" onClick={() => setLines((prev) => [...prev, blankLine()])}>
            <Plus className="size-3.5" />
            {t("costs.form.addCustomLine")}
          </Button>
        </div>
      </div>

      {lines.length === 0 ? (
        !supplierId && <p className="text-xs text-muted-foreground">{t("costs.form.pickSupplierFirst")}</p>
      ) : (
        <ul className="space-y-2">
          {/* Column labels so each line input is self-explanatory. */}
          <li className="grid grid-cols-12 gap-2 px-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            <span className="col-span-5">{t("costs.form.lineDescription")}</span>
            <span className="col-span-2">{t("costs.form.lineQty")}</span>
            <span className="col-span-2">{t("costs.form.lineUnitPrice")}</span>
            <span className="col-span-2 text-right">{t("costs.form.lineTotal")}</span>
            <span className="col-span-1" />
          </li>
          {lines.map((l) => (
            <li key={l.key} className="grid grid-cols-12 items-center gap-2">
              <Input
                aria-label={t("costs.form.lineDescription")}
                className="col-span-5"
                value={l.description}
                placeholder={
                  l.inventoryItemId ? t("costs.form.fromPriceList") : t("costs.form.lineDescription")
                }
                onChange={(e) => updateLine(l.key, { description: e.target.value })}
              />
              <Input
                aria-label={t("costs.form.lineQty")}
                className="col-span-2"
                type="number"
                min={0}
                step="0.001"
                value={l.quantity}
                onChange={(e) => updateLine(l.key, { quantity: e.target.value })}
              />
              <Input
                aria-label={t("costs.form.lineUnitPrice")}
                className="col-span-2"
                type="number"
                min={0}
                step="0.01"
                value={l.unitPrice}
                placeholder="0.00"
                onChange={(e) => updateLine(l.key, { unitPrice: e.target.value })}
              />
              <span className="col-span-2 text-right text-sm tabular-nums text-muted-foreground">
                {(lineTotalMinor(l) / 100).toFixed(2)}
              </span>
              <button
                type="button"
                aria-label={t("costs.form.removeLine")}
                className="col-span-1 text-muted-foreground hover:text-destructive"
                onClick={() => setLines((prev) => prev.filter((x) => x.key !== l.key))}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function SupplierSelect({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation();
  const suppliersQ = useSuppliers({ per_page: 200 });
  const suppliers = suppliersQ.data?.data ?? [];

  return (
    <div className="space-y-2">
      <Label htmlFor="cost_supplier">{t("costs.form.supplier")}</Label>
      <Select id="cost_supplier" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{t("costs.form.noSupplier")}</option>
        {suppliers.map((s) => (
          <option key={s.id} value={s.id}>
            {s.company_name}
          </option>
        ))}
      </Select>
    </div>
  );
}
