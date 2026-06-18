"use client";

import * as React from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Plus } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useWineLots } from "@/hooks/use-cellar";
import { WINE_LOT_STATUSES, type WineLotStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { WineLotForm } from "@/components/cellar/wine-lot-form";
import { lotStatusVariant } from "@/lib/cellar-colors";

type StatusTab = WineLotStatus | "ALL";

export default function WineLotsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const params = useSearchParams();
  const [tab, setTab] = React.useState<StatusTab>("ALL");
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [showForm, setShowForm] = React.useState(params.get("new") === "1");

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError } = useWineLots({
    status: tab === "ALL" ? undefined : tab,
    search: debounced || undefined,
  });

  const tabs = [{ value: "ALL", label: "All" }, ...WINE_LOT_STATUSES.map((s) => ({ value: s, label: t(`cellar.lotStatus.${s}`) }))];

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">{t("cellar.lots")}</h1>
        {can("cellar.manage") && (
          <Button onClick={() => setShowForm(true)}>
            <Plus className="size-4" />
            {t("cellar.addLot")}
          </Button>
        )}
      </header>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as StatusTab)} />
        <Input
          placeholder={t("common.search")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="sm:max-w-xs"
        />
      </div>

      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : isError ? (
        <Card>
          <CardContent className="p-6 text-sm text-destructive">{t("cellar.error")}</CardContent>
        </Card>
      ) : (data?.data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.empty")}</CardContent>
        </Card>
      ) : (
        <div className="space-y-2">
          {(data?.data ?? []).map((lot) => (
            <button
              key={lot.id}
              type="button"
              onClick={() => router.push(`/cellar/lots/${lot.id}`)}
              className="flex w-full items-center justify-between rounded-lg border border-border p-3 text-left hover:bg-muted/50"
            >
              <div>
                <div className="font-medium">{lot.name}</div>
                <div className="text-xs text-muted-foreground">
                  {lot.lot_number} · {lot.grape_variety} {lot.vintage}
                </div>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-sm text-muted-foreground">{Math.round(Number(lot.current_volume))} L</span>
                <Badge variant={lotStatusVariant(lot.status)}>{t(`cellar.lotStatus.${lot.status}`)}</Badge>
              </div>
            </button>
          ))}
        </div>
      )}

      {showForm && (
        <WineLotForm
          onClose={() => setShowForm(false)}
          onSaved={(lot) => {
            setShowForm(false);
            router.push(`/cellar/lots/${lot.id}`);
          }}
        />
      )}
    </div>
  );
}
