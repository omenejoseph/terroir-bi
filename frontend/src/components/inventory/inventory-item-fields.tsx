"use client";

import * as React from "react";

import {
  INVENTORY_CATEGORIES,
  INVENTORY_UNITS,
  type InventoryCategory,
  type InventoryItem,
  type InventoryItemInput,
} from "@/lib/types";
import { useInventoryTaxonomy } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import { Checkbox } from "@/components/ui/checkbox";
import { Combobox } from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

/** Editable item fields as strings (form-friendly), shared by Add and Edit. */
export interface ItemFormState {
  name: string;
  sku: string;
  category: InventoryCategory;
  group: string;
  subcategory: string;
  unit: string;
  vintage: string;
  min_stock: string;
  default_price: string;
  is_active: boolean;
  is_for_sale: boolean;
}

export const EMPTY_ITEM_FORM: ItemFormState = {
  name: "",
  sku: "",
  category: "FINISHED",
  group: "",
  subcategory: "",
  unit: "bottle",
  vintage: "",
  min_stock: "",
  default_price: "",
  is_active: true,
  is_for_sale: false,
};

/** Map an existing item into the editable form state. */
export function itemToForm(item: InventoryItem): ItemFormState {
  return {
    name: item.name,
    sku: item.sku,
    category: (item.category as InventoryCategory) ?? "FINISHED",
    group: item.group ?? "",
    subcategory: item.subcategory ?? "",
    unit: item.unit,
    vintage: item.vintage != null ? String(item.vintage) : "",
    min_stock: item.min_stock != null ? String(item.min_stock) : "",
    default_price: item.default_price ? String(item.default_price.amount) : "",
    is_active: item.is_active,
    is_for_sale: item.is_for_sale,
  };
}

function toNumber(value: string): number | undefined {
  const trimmed = value.trim();
  if (trimmed === "") return undefined;
  const n = Number(trimmed);
  return Number.isFinite(n) ? n : undefined;
}

/** Build the API payload from form state (trims, coerces, nulls empties). */
export function formToInput(form: ItemFormState): InventoryItemInput {
  return {
    name: form.name.trim(),
    sku: form.sku.trim(),
    category: form.category,
    group: form.group.trim() || null,
    subcategory: form.subcategory.trim() || null,
    unit: form.unit.trim(),
    vintage: form.vintage.trim() || null,
    min_stock: toNumber(form.min_stock) ?? null,
    default_price: toNumber(form.default_price) ?? null,
    is_active: form.is_active,
    is_for_sale: form.is_for_sale,
  };
}

function uniqueSorted(values: string[]): string[] {
  return Array.from(new Set(values)).sort((a, b) => a.localeCompare(b));
}

export function Field({
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

/** The shared set of inventory item inputs. State is owned by the caller. */
export function InventoryItemFields({
  form,
  set,
  errors,
}: {
  form: ItemFormState;
  set: <K extends keyof ItemFormState>(key: K, value: ItemFormState[K]) => void;
  errors: Record<string, string>;
}) {
  const { t } = useTranslation();
  const taxonomy = useInventoryTaxonomy().data ?? [];

  // Suggestions narrow down the hierarchy: groups within the chosen category,
  // subcategories within the chosen group. Typing a new value creates it.
  const groupOptions = uniqueSorted(
    taxonomy.filter((e) => e.category === form.category).map((e) => e.group),
  );
  const subcategoryOptions = uniqueSorted(
    taxonomy
      .filter((e) => form.group && e.group === form.group && e.subcategory)
      .map((e) => e.subcategory as string),
  );

  const createLabel = (value: string) => t("inventory.combobox.create", { value });
  const emptyLabel = t("inventory.combobox.empty");

  return (
    <>
      <Field id="name" label={t("inventory.add.nameLabel")} error={errors.name}>
        <Input
          id="name"
          value={form.name}
          onChange={(e) => set("name", e.target.value)}
          placeholder={t("inventory.add.namePlaceholder")}
          required
        />
      </Field>

      <Field id="sku" label={t("inventory.add.skuLabel")} error={errors.sku}>
        <Input
          id="sku"
          value={form.sku}
          onChange={(e) => set("sku", e.target.value)}
          placeholder={t("inventory.add.skuPlaceholder")}
          required
        />
      </Field>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field id="category" label={t("inventory.add.categoryLabel")} error={errors.category}>
          <Select
            id="category"
            value={form.category}
            onChange={(e) => set("category", e.target.value as InventoryCategory)}
          >
            {INVENTORY_CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {t(`inventory.category.${c}`)}
              </option>
            ))}
          </Select>
        </Field>

        <Field id="unit" label={t("inventory.add.unitLabel")} error={errors.unit}>
          <Select id="unit" value={form.unit} onChange={(e) => set("unit", e.target.value)}>
            {INVENTORY_UNITS.map((u) => (
              <option key={u} value={u}>
                {t(`inventory.add.unit.${u}`)}
              </option>
            ))}
          </Select>
        </Field>

        <Field id="group" label={t("inventory.add.groupLabel")} error={errors.group}>
          <Combobox
            id="group"
            value={form.group}
            onChange={(v) => set("group", v)}
            options={groupOptions}
            placeholder={t("inventory.add.groupPlaceholder")}
            createLabel={createLabel}
            emptyLabel={emptyLabel}
          />
        </Field>

        <Field id="subcategory" label={t("inventory.add.subcategoryLabel")} error={errors.subcategory}>
          <Combobox
            id="subcategory"
            value={form.subcategory}
            onChange={(v) => set("subcategory", v)}
            options={subcategoryOptions}
            placeholder={t("inventory.add.subcategoryPlaceholder")}
            createLabel={createLabel}
            emptyLabel={emptyLabel}
          />
        </Field>

        <Field id="vintage" label={t("inventory.add.vintageLabel")} error={errors.vintage}>
          <Input
            id="vintage"
            value={form.vintage}
            onChange={(e) => set("vintage", e.target.value)}
            placeholder={t("inventory.add.vintagePlaceholder")}
          />
        </Field>

        <Field id="min_stock" label={t("inventory.add.minStockLabel")} error={errors.min_stock}>
          <Input
            id="min_stock"
            type="number"
            min={0}
            value={form.min_stock}
            onChange={(e) => set("min_stock", e.target.value)}
          />
        </Field>
      </div>

      <Field id="default_price" label={t("inventory.add.priceLabel")} error={errors.default_price}>
        <Input
          id="default_price"
          type="number"
          min={0}
          value={form.default_price}
          onChange={(e) => set("default_price", e.target.value)}
        />
      </Field>

      <div className="flex flex-wrap gap-6">
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={form.is_active} onChange={(e) => set("is_active", e.target.checked)} />
          {t("inventory.add.isActive")}
        </label>
        <label className="flex items-center gap-2 text-sm">
          <Checkbox
            checked={form.is_for_sale}
            onChange={(e) => set("is_for_sale", e.target.checked)}
          />
          {t("inventory.add.isForSale")}
        </label>
      </div>
    </>
  );
}