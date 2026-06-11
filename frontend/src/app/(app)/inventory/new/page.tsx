"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useCreateInventoryItem } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import {
  EMPTY_ITEM_FORM,
  Field,
  formToInput,
  InventoryItemFields,
  type ItemFormState,
} from "@/components/inventory/inventory-item-fields";

export default function NewInventoryItemPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const create = useCreateInventoryItem();

  const [form, setForm] = React.useState<ItemFormState>(EMPTY_ITEM_FORM);
  const [openingStock, setOpeningStock] = React.useState("");
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

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
      router.push("/inventory");
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
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/inventory"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("inventory.add.back")}
      </Link>

      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("inventory.add.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("inventory.add.description")}</p>
      </div>

      <Card>
        <CardContent className="pt-6">
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
              <Button type="button" variant="outline" onClick={() => router.push("/inventory")}>
                {t("inventory.add.cancel")}
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {create.isPending && <Spinner />}
                {t("inventory.add.submit")}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
