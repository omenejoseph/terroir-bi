"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useCreateSupplier, useUpdateSupplier } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import type { Supplier, SupplierInput } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";

interface FormState {
  company_name: string;
  contact_name: string;
  email: string;
  phone: string;
  address: string;
  city: string;
  country: string;
  tax_id: string;
  bank_account: string;
  payment_terms: string;
  notes: string;
  exclude_from_stats: boolean;
}

const EMPTY: FormState = {
  company_name: "",
  contact_name: "",
  email: "",
  phone: "",
  address: "",
  city: "",
  country: "",
  tax_id: "",
  bank_account: "",
  payment_terms: "",
  notes: "",
  exclude_from_stats: false,
};

function toForm(supplier: Supplier): FormState {
  return {
    company_name: supplier.company_name,
    contact_name: supplier.contact_name ?? "",
    email: supplier.email ?? "",
    phone: supplier.phone ?? "",
    address: supplier.address ?? "",
    city: supplier.city ?? "",
    country: supplier.country ?? "",
    tax_id: supplier.tax_id ?? "",
    bank_account: supplier.bank_account ?? "",
    payment_terms: supplier.payment_terms ?? "",
    notes: supplier.notes ?? "",
    exclude_from_stats: supplier.exclude_from_stats,
  };
}

export function SupplierForm({
  supplier,
  onSaved,
  onCancel,
}: {
  supplier: Supplier | null;
  onSaved: (saved: Supplier) => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const create = useCreateSupplier();
  const update = useUpdateSupplier();

  const isEdit = supplier !== null;
  const [form, setForm] = React.useState<FormState>(supplier ? toForm(supplier) : EMPTY);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  React.useEffect(() => {
    setForm(supplier ? toForm(supplier) : EMPTY);
  }, [supplier]);

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function buildInput(): SupplierInput {
    const trimmed = (v: string) => v.trim() || null;
    return {
      company_name: form.company_name.trim(),
      contact_name: trimmed(form.contact_name),
      email: trimmed(form.email),
      phone: trimmed(form.phone),
      address: trimmed(form.address),
      city: trimmed(form.city),
      country: trimmed(form.country),
      tax_id: trimmed(form.tax_id),
      bank_account: trimmed(form.bank_account),
      payment_terms: trimmed(form.payment_terms),
      notes: trimmed(form.notes),
      exclude_from_stats: form.exclude_from_stats,
    };
  }

  function applyError(err: unknown) {
    if (err instanceof ApiError && err.errors) {
      const flat: Record<string, string> = {};
      for (const [field, messages] of Object.entries(err.errors)) {
        if (messages[0]) flat[field] = messages[0];
      }
      setErrors(flat);
      setFormError(err.message);
    } else {
      setFormError(t("suppliers.form.errorGeneric"));
    }
  }

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setFormError(null);
    try {
      const saved =
        isEdit && supplier
          ? await update.mutateAsync({ id: supplier.id, input: buildInput() })
          : await create.mutateAsync(buildInput());
      onSaved(saved);
    } catch (err) {
      applyError(err);
    }
  }

  const pending = create.isPending || update.isPending;

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <Field id="company_name" label={t("suppliers.form.companyName")} error={errors.company_name}>
        <Input
          id="company_name"
          value={form.company_name}
          onChange={(e) => set("company_name", e.target.value)}
          required
        />
      </Field>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field id="contact_name" label={t("suppliers.form.contactName")} error={errors.contact_name}>
          <Input id="contact_name" value={form.contact_name} onChange={(e) => set("contact_name", e.target.value)} />
        </Field>
        <Field id="email" label={t("suppliers.form.email")} error={errors.email}>
          <Input id="email" type="email" value={form.email} onChange={(e) => set("email", e.target.value)} />
        </Field>
        <Field id="phone" label={t("suppliers.form.phone")} error={errors.phone}>
          <Input id="phone" value={form.phone} onChange={(e) => set("phone", e.target.value)} />
        </Field>
        <Field id="tax_id" label={t("suppliers.form.taxId")} error={errors.tax_id}>
          <Input id="tax_id" value={form.tax_id} onChange={(e) => set("tax_id", e.target.value)} />
        </Field>
      </div>

      <Field id="address" label={t("suppliers.form.address")} error={errors.address}>
        <Input id="address" value={form.address} onChange={(e) => set("address", e.target.value)} />
      </Field>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field id="city" label={t("suppliers.form.city")} error={errors.city}>
          <Input id="city" value={form.city} onChange={(e) => set("city", e.target.value)} />
        </Field>
        <Field id="country" label={t("suppliers.form.country")} error={errors.country}>
          <Input id="country" value={form.country} onChange={(e) => set("country", e.target.value)} />
        </Field>
        <Field id="bank_account" label={t("suppliers.form.bankAccount")} error={errors.bank_account}>
          <Input id="bank_account" value={form.bank_account} onChange={(e) => set("bank_account", e.target.value)} />
        </Field>
        <Field id="payment_terms" label={t("suppliers.form.paymentTerms")} error={errors.payment_terms}>
          <Input id="payment_terms" value={form.payment_terms} onChange={(e) => set("payment_terms", e.target.value)} />
        </Field>
      </div>

      <Field id="notes" label={t("suppliers.form.notes")} error={errors.notes}>
        <Input id="notes" value={form.notes} onChange={(e) => set("notes", e.target.value)} />
      </Field>

      {formError && (
        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
      )}

      <div className="flex justify-end gap-2 border-t border-border pt-4">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("suppliers.form.cancel")}
        </Button>
        <Button type="submit" disabled={pending}>
          {pending && <Spinner />}
          {isEdit ? t("suppliers.form.save") : t("suppliers.form.create")}
        </Button>
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  error,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
