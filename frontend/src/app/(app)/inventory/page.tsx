"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useInventory } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem, Money } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";

function formatMoney(money: Money | null): string {
  if (!money) return "—";
  if (money.formatted) return money.formatted;
  return `${money.currency} ${money.amount}`;
}

export default function InventoryPage() {
  const { t } = useTranslation();
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");

  // Debounce the search input so we don't hit the API on every keystroke.
  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError, error } = useInventory(
    debounced ? { search: debounced } : {},
  );

  const items = data?.data ?? [];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("inventory.title")}</h1>
          <p className="text-sm text-muted-foreground">
            {data?.meta
              ? t("inventory.subtitleCount", { count: data.meta.total })
              : t("inventory.subtitleDefault")}
          </p>
        </div>
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("inventory.searchPlaceholder")}
          className="sm:max-w-xs"
        />
      </header>

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("inventory.errorForbidden")
              : t("inventory.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && items.length === 0 && (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("inventory.empty")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && items.length > 0 && (
        <>
          {/* Mobile: stacked cards. */}
          <div className="space-y-3 md:hidden">
            {items.map((item) => (
              <MobileRow key={item.id} item={item} />
            ))}
          </div>

          {/* Desktop: table. */}
          <Card className="hidden overflow-hidden md:block">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border bg-muted/50 text-left text-muted-foreground">
                  <tr>
                    <Th>{t("inventory.colName")}</Th>
                    <Th>{t("inventory.colSku")}</Th>
                    <Th>{t("inventory.colCategory")}</Th>
                    <Th className="text-right">{t("inventory.colStock")}</Th>
                    <Th className="text-right">{t("inventory.colPrice")}</Th>
                    <Th>{t("inventory.colStatus")}</Th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={item.id} className="border-b border-border last:border-0 hover:bg-muted/30">
                      <Td className="font-medium">{item.name}</Td>
                      <Td className="text-muted-foreground">{item.sku}</Td>
                      <Td>{item.category}</Td>
                      <Td className="text-right tabular-nums">
                        {item.current_stock} {item.unit}
                      </Td>
                      <Td className="text-right tabular-nums">{formatMoney(item.default_price)}</Td>
                      <Td>
                        <StatusBadges item={item} />
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </div>
  );
}

function MobileRow({ item }: { item: InventoryItem }) {
  return (
    <Card>
      <CardContent className="space-y-2 p-4">
        <div className="flex items-start justify-between gap-2">
          <div>
            <p className="font-medium">{item.name}</p>
            <p className="text-xs text-muted-foreground">{item.sku}</p>
          </div>
          <StatusBadges item={item} />
        </div>
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>{item.category}</span>
          <span className="tabular-nums">
            {item.current_stock} {item.unit} · {formatMoney(item.default_price)}
          </span>
        </div>
      </CardContent>
    </Card>
  );
}

function StatusBadges({ item }: { item: InventoryItem }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-wrap gap-1">
      <Badge variant={item.is_active ? "success" : "secondary"}>
        {item.is_active ? t("common.status.active") : t("common.status.inactive")}
      </Badge>
      {item.is_for_sale && <Badge variant="outline">{t("common.forSale")}</Badge>}
    </div>
  );
}

function Th({ children, className }: { children: React.ReactNode; className?: string }) {
  return <th className={`px-4 py-3 font-medium ${className ?? ""}`}>{children}</th>;
}

function Td({ children, className }: { children: React.ReactNode; className?: string }) {
  return <td className={`px-4 py-3 ${className ?? ""}`}>{children}</td>;
}