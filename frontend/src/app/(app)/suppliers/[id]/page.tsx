"use client";

import * as React from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import {
  useAddPriceItem,
  useDeletePriceItem,
  useDeleteSupplier,
  useSupplier,
} from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { SupplierPriceItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";
import { SupplierForm } from "@/components/suppliers/supplier-form";

export default function SupplierDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const confirm = useConfirm();

  const { data: supplier, isLoading, isError } = useSupplier(id);
  const remove = useDeleteSupplier();

  async function handleDelete() {
    if (!supplier) return;
    const ok = await confirm({
      title: t("suppliers.delete.title"),
      description: t("suppliers.delete.body", { name: supplier.company_name }),
      confirmLabel: t("suppliers.delete.action"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(supplier.id);
    router.push("/suppliers");
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/suppliers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("suppliers.back")}
      </Link>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : isError || !supplier ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("suppliers.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="flex items-center justify-between gap-3">
            <h1 className="text-2xl font-semibold tracking-tight">{supplier.company_name}</h1>
            {can("suppliers.delete") && (
              <Button
                variant="ghost"
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={handleDelete}
                disabled={remove.isPending}
              >
                {remove.isPending ? <Spinner /> : <Trash2 className="size-4" />}
                {t("suppliers.delete.action")}
              </Button>
            )}
          </div>

          <Card>
            <CardContent className="pt-6">
              <SupplierForm
                supplier={supplier}
                onSaved={() => router.push("/suppliers")}
                onCancel={() => router.push("/suppliers")}
              />
            </CardContent>
          </Card>

          <PriceListSection supplierId={supplier.id} items={supplier.price_items ?? []} />
        </>
      )}
    </div>
  );
}

function PriceListSection({
  supplierId,
  items,
}: {
  supplierId: string;
  items: SupplierPriceItem[];
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject } = useFormatters();
  const confirm = useConfirm();
  const add = useAddPriceItem();
  const remove = useDeletePriceItem();
  const canManage = can("suppliers.manage");

  const [description, setDescription] = React.useState("");
  const [unitPrice, setUnitPrice] = React.useState("");
  const [unit, setUnit] = React.useState("");

  async function handleAdd(event: React.SyntheticEvent) {
    event.preventDefault();
    if (!description.trim() || unitPrice.trim() === "") return;
    await add.mutateAsync({
      id: supplierId,
      input: {
        description: description.trim(),
        unit_price: Number(unitPrice),
        unit: unit.trim() || null,
      },
    });
    setDescription("");
    setUnitPrice("");
    setUnit("");
  }

  async function handleRemove(item: SupplierPriceItem) {
    const ok = await confirm({
      title: t("suppliers.priceList.deleteTitle"),
      description: t("suppliers.priceList.deleteBody", { description: item.description }),
      confirmLabel: t("suppliers.delete.action"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync({ id: supplierId, priceItemId: item.id });
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <h2 className="text-sm font-semibold">{t("suppliers.priceList.title")}</h2>

        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("suppliers.priceList.empty")}</p>
        ) : (
          <ul className="divide-y divide-border">
            {items.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium">{item.description}</p>
                  <p className="text-xs text-muted-foreground">{item.unit ?? ""}</p>
                </div>
                <div className="flex shrink-0 items-center gap-3">
                  <span className="tabular-nums">{moneyObject(item.unit_price)}</span>
                  {canManage && (
                    <button
                      type="button"
                      aria-label={t("suppliers.priceList.remove")}
                      onClick={() => handleRemove(item)}
                      className="text-muted-foreground hover:text-destructive"
                    >
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {canManage && (
          <form onSubmit={handleAdd} className="grid grid-cols-1 gap-3 border-t border-border pt-4 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
            <div className="space-y-1">
              <Label htmlFor="pi_description" className="text-xs">
                {t("suppliers.priceList.description")}
              </Label>
              <Input
                id="pi_description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="pi_unit_price" className="text-xs">
                {t("suppliers.priceList.unitPrice")}
              </Label>
              <Input
                id="pi_unit_price"
                type="number"
                min={0}
                step="1"
                value={unitPrice}
                onChange={(e) => setUnitPrice(e.target.value)}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="pi_unit" className="text-xs">
                {t("suppliers.priceList.unit")}
              </Label>
              <Input id="pi_unit" value={unit} onChange={(e) => setUnit(e.target.value)} />
            </div>
            <Button type="submit" disabled={add.isPending || !description.trim() || unitPrice.trim() === ""}>
              {add.isPending ? <Spinner /> : <Plus className="size-4" />}
              {t("suppliers.priceList.add")}
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
