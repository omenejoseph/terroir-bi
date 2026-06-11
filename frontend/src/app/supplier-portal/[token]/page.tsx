"use client";

import * as React from "react";
import { useParams } from "next/navigation";
import { Truck, Upload } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useConfirmPublicOrder,
  useImportPublicPriceList,
  usePublicSupplierPortal,
} from "@/hooks/use-supplier-portal";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { withCount } from "@/lib/labels";
import { majorToMinor } from "@/lib/money";
import { APP_NAME } from "@/lib/config";
import type { SupplierOrder } from "@/lib/types";
import { Avatar } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";

type PortalTab = "orders" | "price-list";

/** Read a File as text (FileReader works in browsers and jsdom; Blob.text does not in jsdom). */
function readText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result ?? ""));
    reader.onerror = () => reject(reader.error);
    reader.readAsText(file);
  });
}

/** Parse a "description,price,unit" CSV (header row optional) into upsert rows. */
function parseCsv(text: string): { description: string; unit_price: number; unit: string | null }[] {
  const rows: { description: string; unit_price: number; unit: string | null }[] = [];
  const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  lines.forEach((line, i) => {
    const cols = line.split(",").map((c) => c.trim());
    if (i === 0 && /description/i.test(cols[0] ?? "")) return; // skip a header row
    const description = cols[0];
    const priceMinor = majorToMinor(cols[1] ?? "");
    if (!description || priceMinor === null) return;
    rows.push({ description, unit_price: priceMinor, unit: cols[2] || null });
  });
  return rows;
}

export default function SupplierPortalPage() {
  const params = useParams<{ token: string }>();
  const token = params?.token;
  const { t } = useTranslation();
  const portalQ = usePublicSupplierPortal(token);
  const [tab, setTab] = React.useState<PortalTab>("orders");

  const portal = portalQ.data;

  return (
    <div className="min-h-screen bg-muted/30 px-4 py-10">
      <div className="mx-auto max-w-2xl space-y-6">
        <header className="flex items-center gap-2">
          <Truck className="size-5 text-primary" />
          <span className="text-sm font-semibold text-muted-foreground">{APP_NAME}</span>
        </header>

        {portalQ.isLoading ? (
          <div className="flex justify-center py-20">
            <Spinner className="size-6 text-muted-foreground" />
          </div>
        ) : portalQ.isError || !portal ? (
          <Card>
            <CardContent className="py-16 text-center text-sm text-muted-foreground">
              {t("supplierPortal.invalid")}
            </CardContent>
          </Card>
        ) : (
          <>
            <div className="flex items-center gap-3">
              <Avatar name={portal.supplier.contact_name ?? portal.supplier.company_name} />
              <div>
                <h1 className="text-xl font-semibold tracking-tight">
                  {portal.supplier.company_name}
                </h1>
                {portal.supplier.contact_name && (
                  <p className="text-sm text-muted-foreground">{portal.supplier.contact_name}</p>
                )}
              </div>
            </div>

            <Tabs
              tabs={[
                { value: "orders", label: withCount(t("supplierPortal.tabs.orders"), portal.orders.length) },
                {
                  value: "price-list",
                  label: withCount(t("supplierPortal.tabs.priceList"), portal.price_items.length),
                },
              ]}
              value={tab}
              onChange={(v) => setTab(v as PortalTab)}
            />

            {tab === "orders" ? (
              <OrdersTab token={token ?? ""} orders={portal.orders} />
            ) : (
              <PriceListTab token={token ?? ""} items={portal.price_items} />
            )}
          </>
        )}
      </div>
    </div>
  );
}

function OrdersTab({ token, orders }: { token: string; orders: SupplierOrder[] }) {
  const { t } = useTranslation();
  if (orders.length === 0) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          {t("supplierPortal.orders.empty")}
        </CardContent>
      </Card>
    );
  }
  return (
    <div className="space-y-3">
      {orders.map((o) => (
        <PortalOrderCard key={o.id} token={token} order={o} />
      ))}
    </div>
  );
}

function PortalOrderCard({ token, order }: { token: string; order: SupplierOrder }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirmPublicOrder(token);

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="font-medium">{order.order_number}</p>
            {order.expected_at && (
              <p className="text-xs text-muted-foreground">
                {t("supplierPortal.orders.expected")}: {date(order.expected_at)}
              </p>
            )}
          </div>
          <div className="flex flex-col items-end gap-1">
            <Badge variant={order.status === "SENT" ? "default" : "success"}>
              {t(`supplierPortal.status.${order.status}`)}
            </Badge>
            <span className="text-sm font-semibold tabular-nums">
              {moneyObject(order.total_amount)}
            </span>
          </div>
        </div>

        {order.items && order.items.length > 0 && (
          <ul className="divide-y divide-border border-t border-border text-sm">
            {order.items.map((i) => (
              <li key={i.id} className="flex items-center justify-between gap-3 py-2">
                <span className="min-w-0 truncate">{i.description}</span>
                <span className="shrink-0 tabular-nums text-muted-foreground">
                  {i.quantity}
                  {i.unit ? ` ${i.unit}` : ""} · {moneyObject(i.total)}
                </span>
              </li>
            ))}
          </ul>
        )}

        {order.status === "SENT" && (
          <div className="flex justify-end">
            <Button size="sm" onClick={() => confirm.mutate(order.id)} disabled={confirm.isPending}>
              {confirm.isPending && <Spinner />}
              {t("supplierPortal.orders.confirm")}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function PriceListTab({
  token,
  items,
}: {
  token: string;
  items: { id: string; description: string; unit_price: import("@/lib/types").Money; unit: string | null }[];
}) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const importItems = useImportPublicPriceList(token);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const [result, setResult] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  async function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = ""; // allow re-selecting the same file
    if (!file) return;
    setError(null);
    setResult(null);
    const rows = parseCsv(await readText(file));
    if (rows.length === 0) {
      setError(t("supplierPortal.priceList.noRows"));
      return;
    }
    try {
      const res = await importItems.mutateAsync(rows);
      setResult(t("supplierPortal.priceList.done", { added: res.added, updated: res.updated }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("supplierPortal.priceList.error"));
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="space-y-3 pt-6">
          <h2 className="text-sm font-semibold">{t("supplierPortal.priceList.uploadTitle")}</h2>
          <p className="text-xs text-muted-foreground">{t("supplierPortal.priceList.help")}</p>
          <input
            ref={inputRef}
            type="file"
            accept=".csv,text/csv"
            className="hidden"
            aria-label={t("supplierPortal.priceList.uploadCsv")}
            onChange={onFile}
          />
          <Button
            type="button"
            variant="outline"
            onClick={() => inputRef.current?.click()}
            disabled={importItems.isPending}
          >
            {importItems.isPending ? <Spinner /> : <Upload className="size-4" />}
            {t("supplierPortal.priceList.uploadCsv")}
          </Button>
          {result && (
            <p className="rounded-md bg-success/10 px-3 py-2 text-sm text-success">{result}</p>
          )}
          {error && <p className="text-sm text-destructive">{error}</p>}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-3 pt-6">
          <h2 className="text-sm font-semibold">{t("supplierPortal.priceList.currentTitle")}</h2>
          {items.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("supplierPortal.priceList.empty")}</p>
          ) : (
            <ul className="divide-y divide-border">
              {items.map((item) => (
                <li key={item.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{item.description}</p>
                    {item.unit && <p className="text-xs text-muted-foreground">{item.unit}</p>}
                  </div>
                  <span className="shrink-0 tabular-nums">{moneyObject(item.unit_price)}</span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
