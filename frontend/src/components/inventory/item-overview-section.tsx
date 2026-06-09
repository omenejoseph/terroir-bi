"use client";

import * as React from "react";
import { Pencil } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useUpdateInventoryItem } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem, Money } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import {
  formToInput,
  InventoryItemFields,
  itemToForm,
  type ItemFormState,
} from "@/components/inventory/inventory-item-fields";

function formatMoney(money: Money | null): string {
  if (!money) return "—";
  return money.formatted ?? `${money.currency} ${money.amount}`;
}

export function ItemOverviewSection({
  item,
  canManage,
}: {
  item: InventoryItem;
  canManage: boolean;
}) {
  const { t } = useTranslation();
  const update = useUpdateInventoryItem();

  const [editing, setEditing] = React.useState(false);
  const [form, setForm] = React.useState<ItemFormState>(() => itemToForm(item));
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  function startEdit() {
    setForm(itemToForm(item));
    setErrors({});
    setFormError(null);
    setEditing(true);
  }

  function set<K extends keyof ItemFormState>(key: K, value: ItemFormState[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function save(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setFormError(null);
    try {
      await update.mutateAsync({ id: item.id, input: formToInput(form) });
      setEditing(false);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        setFormError(err.message);
      } else {
        setFormError(t("inventory.details.errorGeneric"));
      }
    }
  }

  return (
    <Card>
      <CardContent className="pt-6">
        {canManage && !editing && (
          <div className="mb-4 flex justify-end">
            <Button variant="outline" size="sm" onClick={startEdit}>
              <Pencil className="size-4" />
              {t("inventory.details.edit")}
            </Button>
          </div>
        )}
        {editing ? (
          <form onSubmit={save} className="space-y-4">
            <InventoryItemFields form={form} set={set} errors={errors} />
            {formError && (
              <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {formError}
              </p>
            )}
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                {t("inventory.details.cancel")}
              </Button>
              <Button type="submit" disabled={update.isPending}>
                {update.isPending && <Spinner />}
                {t("inventory.details.save")}
              </Button>
            </div>
          </form>
        ) : (
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
            <Detail label={t("inventory.details.category")}>
              {t(`inventory.category.${item.category}`)}
            </Detail>
            <Detail label={t("inventory.add.groupLabel")}>{item.group ?? "—"}</Detail>
            <Detail label={t("inventory.add.subcategoryLabel")}>{item.subcategory ?? "—"}</Detail>
            <Detail label={t("inventory.details.unit")}>
              {t(`inventory.add.unit.${item.unit}`)}
            </Detail>
            <Detail label={t("inventory.details.vintage")}>{item.vintage ?? "—"}</Detail>
            <Detail label={t("inventory.details.minStock")}>{item.min_stock ?? "—"}</Detail>
            <Detail label={t("inventory.details.price")}>{formatMoney(item.default_price)}</Detail>
            <Detail label={t("inventory.details.status")}>
              <span className="flex flex-wrap gap-1">
                <Badge variant={item.is_active ? "success" : "secondary"}>
                  {item.is_active ? t("common.status.active") : t("common.status.inactive")}
                </Badge>
                {item.is_for_sale && <Badge variant="outline">{t("common.forSale")}</Badge>}
              </span>
            </Detail>
          </dl>
        )}
      </CardContent>
    </Card>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium">{children}</dd>
    </div>
  );
}