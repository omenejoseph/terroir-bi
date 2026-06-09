"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useCreateInventoryItem } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import {
  EMPTY_ITEM_FORM,
  Field,
  formToInput,
  InventoryItemFields,
  type ItemFormState,
} from "@/components/inventory/inventory-item-fields";

export function AddInventoryItemDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const create = useCreateInventoryItem();

  const [form, setForm] = React.useState<ItemFormState>(EMPTY_ITEM_FORM);
  const [openingStock, setOpeningStock] = React.useState("");
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  // Reset whenever the dialog is (re)opened.
  React.useEffect(() => {
    if (open) {
      setForm(EMPTY_ITEM_FORM);
      setOpeningStock("");
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  function set<K extends keyof ItemFormState>(key: K, value: ItemFormState[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setFormError(null);

    const opening = Number(openingStock.trim());

    try {
      await create.mutateAsync({
        input: formToInput(form),
        openingStock: Number.isFinite(opening) ? opening : null,
      });
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        setFormError(err.message);
      } else {
        setFormError(t("inventory.add.errorGeneric"));
      }
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title={t("inventory.add.title")}
      description={t("inventory.add.description")}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <InventoryItemFields form={form} set={set} errors={errors} />

        <Field
          id="opening_stock"
          label={t("inventory.add.openingStockLabel")}
          error={errors.quantity}
        >
          <Input
            id="opening_stock"
            type="number"
            min={0}
            value={openingStock}
            onChange={(e) => setOpeningStock(e.target.value)}
            placeholder="0"
          />
        </Field>

        {formError && (
          <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {formError}
          </p>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t("inventory.add.cancel")}
          </Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Spinner />}
            {t("inventory.add.submit")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}