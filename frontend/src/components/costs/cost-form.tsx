"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useCostCategories, useCreateCost } from "@/hooks/use-costs";
import { useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import { majorToMinor } from "@/lib/money";
import { COST_STATUSES, PAYMENT_METHODS, type CostInput, type CostStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function CostForm({
  onSaved,
  onCancel,
}: {
  onSaved: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const create = useCreateCost();
  const categoriesQ = useCostCategories();
  const canViewSuppliers = can("suppliers.view");

  const [costDate, setCostDate] = React.useState(today());
  const [totalAmount, setTotalAmount] = React.useState("");
  const [category, setCategory] = React.useState("");
  const [description, setDescription] = React.useState("");
  const [reference, setReference] = React.useState("");
  const [status, setStatus] = React.useState<CostStatus>("PENDING");
  const [paymentMethod, setPaymentMethod] = React.useState("");
  const [supplierId, setSupplierId] = React.useState("");
  const [notes, setNotes] = React.useState("");
  const [formError, setFormError] = React.useState<string | null>(null);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setFormError(null);
    const input: CostInput = {
      total_amount: majorToMinor(totalAmount) ?? 0, // amounts entered in major units (€)
      category: category.trim(),
      ...(costDate ? { date: costDate } : {}),
      ...(description.trim() ? { description: description.trim() } : {}),
      ...(reference.trim() ? { reference: reference.trim() } : {}),
      status,
      ...(paymentMethod ? { payment_method: paymentMethod } : {}),
      ...(supplierId ? { supplier_id: supplierId } : {}),
      ...(notes.trim() ? { notes: notes.trim() } : {}),
    };
    try {
      await create.mutateAsync(input);
      onSaved();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("costs.form.errorGeneric"));
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="cost_date">{t("costs.form.date")}</Label>
          <Input id="cost_date" type="date" value={costDate} onChange={(e) => setCostDate(e.target.value)} required />
        </div>
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
          <Label htmlFor="cost_status">{t("costs.form.status")}</Label>
          <Select id="cost_status" value={status} onChange={(e) => setStatus(e.target.value as CostStatus)}>
            {COST_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`costs.status.${s}`)}
              </option>
            ))}
          </Select>
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

      {canViewSuppliers && <SupplierSelect value={supplierId} onChange={setSupplierId} />}

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

      {formError && (
        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
      )}

      <div className="flex justify-end gap-2 border-t border-border pt-4">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("costs.form.cancel")}
        </Button>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending && <Spinner />}
          {t("costs.form.create")}
        </Button>
      </div>
    </form>
  );
}

function SupplierSelect({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation();
  const suppliersQ = useSuppliers();
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
