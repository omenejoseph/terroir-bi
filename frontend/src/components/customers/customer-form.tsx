"use client";

import * as React from "react";
import { Plus, Trash2, X } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useCreateCustomer,
  useCreatePricingTier,
  useCustomerTypes,
  useDeleteCustomer,
  usePricingTiers,
  useUpdateCustomer,
} from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import type { Customer } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { YesNoToggle } from "@/components/ui/yes-no-toggle";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Combobox } from "@/components/ui/combobox";
import { useConfirm } from "@/components/ui/confirm";
import { Spinner } from "@/components/ui/spinner";

interface FormState {
  company_name: string;
  email: string;
  contact_name: string;
  phone: string;
  address: string;
  city: string;
  zip: string;
  country: string;
  oib: string;
  customer_type: string;
  pricing_tier_id: string;
  rebate_percent: string;
  hide_prices: boolean;
  is_agency: boolean;
  allow_single_bottle: boolean;
  exclude_from_stats: boolean;
  is_active: boolean;
}

const EMPTY: FormState = {
  company_name: "",
  email: "",
  contact_name: "",
  phone: "",
  address: "",
  city: "",
  zip: "",
  country: "",
  oib: "",
  customer_type: "",
  pricing_tier_id: "",
  rebate_percent: "",
  hide_prices: false,
  is_agency: false,
  allow_single_bottle: false,
  exclude_from_stats: false,
  is_active: true,
};

function toForm(customer: Customer): FormState {
  return {
    company_name: customer.company_name,
    email: customer.email,
    contact_name: customer.contact_name ?? "",
    phone: customer.phone ?? "",
    address: customer.address ?? "",
    city: customer.city ?? "",
    zip: customer.zip ?? "",
    country: customer.country ?? "",
    oib: customer.oib ?? "",
    customer_type: customer.customer_type ?? "",
    pricing_tier_id: customer.pricing_tier?.id ?? "",
    rebate_percent: customer.rebate_percent ?? "",
    hide_prices: customer.hide_prices,
    is_agency: customer.is_agency ?? false,
    allow_single_bottle: customer.allow_single_bottle ?? false,
    exclude_from_stats: customer.exclude_from_stats ?? false,
    is_active: customer.is_active,
  };
}

export function CustomerForm({
  customer,
  onSaved,
  onCancel,
  onDeleted,
  bare = false,
}: {
  customer: Customer | null;
  onSaved: () => void;
  onCancel: () => void;
  onDeleted?: () => void;
  /** Render the form without the surrounding Card (e.g. inside an expandable panel). */
  bare?: boolean;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const confirm = useConfirm();
  const create = useCreateCustomer();
  const update = useUpdateCustomer();
  const remove = useDeleteCustomer();
  const createTier = useCreatePricingTier();
  const tiersQ = usePricingTiers();
  const customerTypes = useCustomerTypes();

  const isEdit = customer !== null;
  const [form, setForm] = React.useState<FormState>(customer ? toForm(customer) : EMPTY);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  // Inline pricing-tier creation.
  const [addingTier, setAddingTier] = React.useState(false);
  const [tierName, setTierName] = React.useState("");
  const [tierRebate, setTierRebate] = React.useState("");
  const [tierError, setTierError] = React.useState<string | null>(null);

  // Re-sync when the target customer changes (e.g. after the detail page loads it).
  React.useEffect(() => {
    setForm(customer ? toForm(customer) : EMPTY);
  }, [customer]);

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function buildInput() {
    const trimmed = (v: string) => v.trim() || null;
    const rebate = form.rebate_percent.trim();
    return {
      company_name: form.company_name.trim(),
      email: form.email.trim(),
      contact_name: trimmed(form.contact_name),
      phone: trimmed(form.phone),
      address: trimmed(form.address),
      city: trimmed(form.city),
      zip: trimmed(form.zip),
      country: trimmed(form.country),
      oib: trimmed(form.oib),
      customer_type: trimmed(form.customer_type),
      pricing_tier_id: form.pricing_tier_id || null,
      hide_prices: form.hide_prices,
      is_agency: form.is_agency,
      allow_single_bottle: form.allow_single_bottle,
      exclude_from_stats: form.exclude_from_stats,
      is_active: form.is_active,
      ...(rebate === "" ? {} : { rebate_percent: Number(rebate) }),
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
      setFormError(t("customers.form.errorGeneric"));
    }
  }

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setFormError(null);
    try {
      if (isEdit && customer) {
        await update.mutateAsync({ id: customer.id, input: buildInput() });
      } else {
        await create.mutateAsync(buildInput());
      }
      onSaved();
    } catch (err) {
      applyError(err);
    }
  }

  async function handleDelete() {
    if (!customer) return;
    const ok = await confirm({
      title: t("customers.form.deleteConfirmTitle"),
      description: t("customers.form.deleteConfirmBody", { name: customer.company_name }),
      confirmLabel: t("customers.form.delete"),
      tone: "danger",
    });
    if (!ok) return;
    setFormError(null);
    try {
      await remove.mutateAsync(customer.id);
      onDeleted?.();
    } catch (err) {
      applyError(err);
    }
  }

  async function handleCreateTier() {
    setTierError(null);
    const name = tierName.trim();
    if (!name) return;
    const rebate = tierRebate.trim();
    try {
      const tier = await createTier.mutateAsync({
        name,
        ...(rebate === "" ? {} : { rebate_percent: Number(rebate) }),
      });
      set("pricing_tier_id", tier.id);
      setAddingTier(false);
      setTierName("");
      setTierRebate("");
    } catch (err) {
      setTierError(err instanceof ApiError ? (err.fieldError("name") ?? err.message) : t("customers.form.tierError"));
    }
  }

  const tiers = tiersQ.data ?? [];
  const pending = create.isPending || update.isPending;
  const canDelete = isEdit && can("customers.delete");
  const canManageTiers = can("pricing.manage");

  const formBody = (
    <form onSubmit={handleSubmit} className="space-y-4">
          <Field id="company_name" label={t("customers.form.company")} error={errors.company_name}>
            <Input
              id="company_name"
              value={form.company_name}
              onChange={(e) => set("company_name", e.target.value)}
              placeholder={t("customers.form.companyPlaceholder")}
              required
            />
          </Field>

          <Field id="email" label={t("customers.form.email")} error={errors.email}>
            <Input
              id="email"
              type="email"
              value={form.email}
              onChange={(e) => set("email", e.target.value)}
              placeholder={t("customers.form.emailPlaceholder")}
              required
            />
          </Field>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field id="contact_name" label={t("customers.form.contact")} error={errors.contact_name}>
              <Input
                id="contact_name"
                value={form.contact_name}
                onChange={(e) => set("contact_name", e.target.value)}
              />
            </Field>
            <Field id="phone" label={t("customers.form.phone")} error={errors.phone}>
              <Input id="phone" value={form.phone} onChange={(e) => set("phone", e.target.value)} />
            </Field>
            <Field id="oib" label={t("customers.form.oib")} error={errors.oib}>
              <Input id="oib" value={form.oib} onChange={(e) => set("oib", e.target.value)} />
            </Field>
            <Field id="customer_type" label={t("customers.form.customerType")} error={errors.customer_type}>
              <Combobox
                id="customer_type"
                value={form.customer_type}
                onChange={(v) => set("customer_type", v)}
                options={customerTypes}
                placeholder={t("customers.form.customerTypePlaceholder")}
                createLabel={(value) => t("customers.form.typeCreate", { value })}
                emptyLabel={t("customers.form.typeEmpty")}
              />
            </Field>
          </div>

          {/* Pricing tier — with inline "new tier" creation */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="pricing_tier_id">{t("customers.form.tierLabel")}</Label>
              {canManageTiers && !addingTier && (
                <button
                  type="button"
                  onClick={() => setAddingTier(true)}
                  className="flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                  <Plus className="size-3.5" />
                  {t("customers.form.newTier")}
                </button>
              )}
            </div>
            <Select
              id="pricing_tier_id"
              value={form.pricing_tier_id}
              onChange={(e) => set("pricing_tier_id", e.target.value)}
            >
              <option value="">{t("customers.form.tierNone")}</option>
              {tiers.map((tier) => (
                <option key={tier.id} value={tier.id}>
                  {tier.name} ({tier.rebate_percent}%)
                </option>
              ))}
            </Select>
            {errors.pricing_tier_id && (
              <p className="text-sm text-destructive">{errors.pricing_tier_id}</p>
            )}

            {addingTier && (
              <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
                <div className="flex items-center justify-between">
                  <p className="text-sm font-medium">{t("customers.form.newTier")}</p>
                  <button
                    type="button"
                    aria-label={t("customers.form.cancel")}
                    onClick={() => setAddingTier(false)}
                    className="text-muted-foreground hover:text-foreground"
                  >
                    <X className="size-4" />
                  </button>
                </div>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="tier_name" className="text-xs">
                      {t("customers.form.tierName")}
                    </Label>
                    <Input
                      id="tier_name"
                      value={tierName}
                      onChange={(e) => setTierName(e.target.value)}
                      placeholder={t("customers.form.tierNamePlaceholder")}
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="tier_rebate" className="text-xs">
                      {t("customers.form.tierRebate")}
                    </Label>
                    <Input
                      id="tier_rebate"
                      type="number"
                      min={0}
                      max={100}
                      step="any"
                      value={tierRebate}
                      onChange={(e) => setTierRebate(e.target.value)}
                    />
                  </div>
                </div>
                {tierError && <p className="text-sm text-destructive">{tierError}</p>}
                <Button
                  type="button"
                  size="sm"
                  onClick={handleCreateTier}
                  disabled={createTier.isPending || tierName.trim() === ""}
                >
                  {createTier.isPending && <Spinner />}
                  {t("customers.form.tierCreate")}
                </Button>
              </div>
            )}
          </div>

          <Field id="rebate_percent" label={t("customers.form.rebate")} error={errors.rebate_percent}>
            <Input
              id="rebate_percent"
              type="number"
              min={0}
              max={100}
              step="any"
              value={form.rebate_percent}
              onChange={(e) => set("rebate_percent", e.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t("customers.form.rebateHint")}</p>
          </Field>

          <Field id="address" label={t("customers.form.address")} error={errors.address}>
            <Input id="address" value={form.address} onChange={(e) => set("address", e.target.value)} />
          </Field>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field id="city" label={t("customers.form.city")} error={errors.city}>
              <Input id="city" value={form.city} onChange={(e) => set("city", e.target.value)} />
            </Field>
            <Field id="zip" label={t("customers.form.zip")} error={errors.zip}>
              <Input id="zip" value={form.zip} onChange={(e) => set("zip", e.target.value)} />
            </Field>
            <Field id="country" label={t("customers.form.country")} error={errors.country}>
              <Input id="country" value={form.country} onChange={(e) => set("country", e.target.value)} />
            </Field>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <span className="text-sm">{t("customers.form.hidePrices")}</span>
              <YesNoToggle
                value={form.hide_prices}
                onChange={(v) => set("hide_prices", v)}
                yesLabel={t("common.yes")}
                noLabel={t("common.no")}
              />
            </div>
            <div className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <span className="text-sm">{t("customers.form.excludeFromStats")}</span>
              <YesNoToggle
                value={form.exclude_from_stats}
                onChange={(v) => set("exclude_from_stats", v)}
                yesLabel={t("common.yes")}
                noLabel={t("common.no")}
              />
            </div>
            <div className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <span className="text-sm">{t("customers.form.isAgency")}</span>
              <YesNoToggle
                value={form.is_agency}
                onChange={(v) => set("is_agency", v)}
                yesLabel={t("common.yes")}
                noLabel={t("common.no")}
              />
            </div>
            <div className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <span className="text-sm">{t("customers.form.allowSingleBottle")}</span>
              <YesNoToggle
                value={form.allow_single_bottle}
                onChange={(v) => set("allow_single_bottle", v)}
                yesLabel={t("common.yes")}
                noLabel={t("common.no")}
              />
            </div>
          </div>

          {formError && (
            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
          )}

          <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
            <div>
              {canDelete && (
                <Button
                  type="button"
                  variant="ghost"
                  className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                  onClick={handleDelete}
                  disabled={remove.isPending}
                >
                  {remove.isPending ? <Spinner /> : <Trash2 className="size-4" />}
                  {t("customers.form.delete")}
                </Button>
              )}
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={onCancel}>
                {t("customers.form.cancel")}
              </Button>
              <Button type="submit" disabled={pending}>
                {pending && <Spinner />}
                {isEdit ? t("customers.form.save") : t("customers.form.create")}
              </Button>
            </div>
          </div>
    </form>
  );

  if (bare) return formBody;

  return (
    <Card>
      <CardContent className="pt-6">{formBody}</CardContent>
    </Card>
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