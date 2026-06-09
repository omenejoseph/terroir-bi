"use client";

import * as React from "react";

import {
  INVENTORY_CATEGORIES,
  INVENTORY_UNITS,
  SALES_UNITS,
  UNIT_SIZE_UNITS,
  type InventoryCategory,
  type InventoryItem,
  type InventoryItemInput,
  type SalesUnit,
  type UnitSizeUnit,
} from "@/lib/types";
import { majorToMinor, minorToMajorInput } from "@/lib/money";
import { useInventoryTaxonomy } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import { Combobox } from "@/components/ui/combobox";
import { InfoHint } from "@/components/ui/info-hint";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { YesNoToggle } from "@/components/ui/yes-no-toggle";

/** Editable item fields as strings (form-friendly), shared by Add and Edit. */
export interface ItemFormState {
  name: string;
  sku: string;
  category: InventoryCategory;
  group: string;
  subcategory: string;
  unit: string;
  unit_size_value: string;
  unit_size_unit: UnitSizeUnit;
  sales_unit: SalesUnit;
  bottles_per_case: string;
  cost_per_unit: string;
  vintage: string;
  min_stock: string;
  default_price: string;
  is_for_sale: boolean;
  hide_from_portal: boolean;
}

export const EMPTY_ITEM_FORM: ItemFormState = {
  name: "",
  sku: "",
  category: "FINISHED",
  group: "",
  subcategory: "",
  unit: "bottle",
  unit_size_value: "",
  unit_size_unit: "ml",
  sales_unit: "bottles",
  bottles_per_case: "12",
  cost_per_unit: "",
  vintage: "",
  min_stock: "",
  default_price: "",
  is_for_sale: false,
  hide_from_portal: false,
};

/** Split a stored unit_size like "750ml" into its value + unit parts. */
function parseUnitSize(raw: string | null): { value: string; unit: UnitSizeUnit } {
  const match = (raw ?? "").trim().match(/^([\d.]+)\s*(ml|cl|l|gr|kg)$/i);
  if (!match) return { value: "", unit: "ml" };
  return { value: match[1], unit: match[2].toLowerCase() as UnitSizeUnit };
}

/** Map an existing item into the editable form state. */
export function itemToForm(item: InventoryItem): ItemFormState {
  const size = parseUnitSize(item.unit_size);
  return {
    name: item.name,
    sku: item.sku,
    category: (item.category as InventoryCategory) ?? "FINISHED",
    group: item.group ?? "",
    subcategory: item.subcategory ?? "",
    unit: item.unit,
    unit_size_value: size.value,
    unit_size_unit: size.unit,
    sales_unit: (item.sales_unit as SalesUnit) ?? "bottles",
    bottles_per_case: item.bottles_per_case != null ? String(item.bottles_per_case) : "12",
    cost_per_unit: minorToMajorInput(item.cost_per_unit?.minor),
    vintage: item.vintage != null ? String(item.vintage) : "",
    min_stock: item.min_stock != null ? String(item.min_stock) : "",
    default_price: minorToMajorInput(item.default_price?.minor),
    is_for_sale: item.is_for_sale,
    hide_from_portal: item.hide_from_portal ?? false,
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
  const sizeValue = form.unit_size_value.trim();
  return {
    name: form.name.trim(),
    sku: form.sku.trim(),
    category: form.category,
    unit: form.unit.trim(),
    sales_unit: form.sales_unit,
    bottles_per_case: toNumber(form.bottles_per_case) ?? 1,
    // Price & cost are entered in major units, per sales unit.
    cost_per_unit: majorToMinor(form.cost_per_unit) ?? 0,
    default_price: majorToMinor(form.default_price),
    group: form.group.trim() || null,
    subcategory: form.subcategory.trim() || null,
    unit_size: sizeValue ? `${sizeValue}${form.unit_size_unit}` : null,
    vintage: form.vintage.trim() || null,
    min_stock: toNumber(form.min_stock) ?? null,
    is_for_sale: form.is_for_sale,
    hide_from_portal: form.hide_from_portal,
  };
}

function uniqueSorted(values: string[]): string[] {
  return Array.from(new Set(values)).sort((a, b) => a.localeCompare(b));
}

export function Field({
  id,
  label,
  error,
  hint,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-1.5">
        <Label htmlFor={id}>{label}</Label>
        {hint && <InfoHint text={hint} />}
      </div>
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

        <Field
          id="unit"
          label={t("inventory.add.unitLabel")}
          error={errors.unit}
          hint={t("inventory.add.unitHint")}
        >
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

        <Field
          id="unit_size_value"
          label={t("inventory.add.unitSizeLabel")}
          error={errors.unit_size}
          hint={t("inventory.add.unitSizeHint")}
        >
          <div className="flex gap-2">
            <Input
              id="unit_size_value"
              type="number"
              min={0}
              step="any"
              value={form.unit_size_value}
              onChange={(e) => set("unit_size_value", e.target.value)}
              placeholder={t("inventory.add.unitSizePlaceholder")}
              className="flex-1"
            />
            <Select
              value={form.unit_size_unit}
              onChange={(e) => set("unit_size_unit", e.target.value as UnitSizeUnit)}
              aria-label={t("inventory.add.unitSizeUnitLabel")}
              className="w-24"
            >
              {UNIT_SIZE_UNITS.map((u) => (
                <option key={u} value={u}>
                  {u}
                </option>
              ))}
            </Select>
          </div>
        </Field>

        <Field
          id="sales_unit"
          label={t("inventory.add.salesUnitLabel")}
          error={errors.sales_unit}
          hint={t("inventory.add.salesUnitHint")}
        >
          <Select
            id="sales_unit"
            value={form.sales_unit}
            onChange={(e) => set("sales_unit", e.target.value as SalesUnit)}
          >
            {SALES_UNITS.map((u) => (
              <option key={u} value={u}>
                {t(`inventory.add.salesUnit.${u}`)}
              </option>
            ))}
          </Select>
        </Field>

        <Field
          id="bottles_per_case"
          label={t("inventory.add.bottlesPerCaseLabel")}
          error={errors.bottles_per_case}
          hint={t("inventory.add.bottlesPerCaseHint")}
        >
          <Input
            id="bottles_per_case"
            type="number"
            min={1}
            value={form.bottles_per_case}
            onChange={(e) => set("bottles_per_case", e.target.value)}
            required
          />
        </Field>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field
          id="default_price"
          label={t("inventory.add.priceLabel")}
          error={errors.default_price}
          hint={t("inventory.add.priceHint")}
        >
          <Input
            id="default_price"
            type="number"
            min={0}
            step="0.01"
            value={form.default_price}
            onChange={(e) => set("default_price", e.target.value)}
          />
        </Field>

        <Field
          id="cost_per_unit"
          label={t("inventory.add.costLabel")}
          error={errors.cost_per_unit}
          hint={t("inventory.add.costHint")}
        >
          <Input
            id="cost_per_unit"
            type="number"
            min={0}
            step="0.01"
            value={form.cost_per_unit}
            onChange={(e) => set("cost_per_unit", e.target.value)}
            required
          />
        </Field>
      </div>

      <div className="flex flex-wrap gap-8">
        <Field
          id="is_for_sale"
          label={t("inventory.add.isForSale")}
          hint={t("inventory.add.isForSaleHint")}
        >
          <YesNoToggle
            id="is_for_sale"
            value={form.is_for_sale}
            onChange={(v) => set("is_for_sale", v)}
            yesLabel={t("common.yes")}
            noLabel={t("common.no")}
          />
        </Field>

        <Field
          id="hide_from_portal"
          label={t("inventory.add.hideFromPortal")}
          hint={t("inventory.add.hideFromPortalHint")}
        >
          <YesNoToggle
            id="hide_from_portal"
            value={form.hide_from_portal}
            onChange={(v) => set("hide_from_portal", v)}
            yesLabel={t("common.yes")}
            noLabel={t("common.no")}
          />
        </Field>
      </div>
    </>
  );
}