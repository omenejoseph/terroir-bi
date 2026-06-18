"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Plus, Wine } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useVessels, useWineLots } from "@/hooks/use-cellar";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { CellarMap } from "@/components/cellar/cellar-map";
import { CellarSubnav } from "@/components/cellar/cellar-subnav";
import { WineLotsByVintage } from "@/components/cellar/wine-lots-by-vintage";

export default function CellarPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const vessels = useVessels();
  const lots = useWineLots({ exclude_bottled: true, per_page: 200 });

  const totalVolume = React.useMemo(
    () => (vessels.data ?? []).reduce((sum, v) => sum + Number(v.current_volume || 0), 0),
    [vessels.data],
  );

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold">
            <Wine className="size-6" /> {t("cellar.title")}
          </h1>
          <p className="text-sm text-muted-foreground">{t("cellar.subtitle")}</p>
        </div>
        {can("cellar.manage") && (
          <Button onClick={() => router.push("/cellar/lots?new=1")}>
            <Plus className="size-4" />
            {t("cellar.addLot")}
          </Button>
        )}
      </header>

      <CellarSubnav />

      <div className="grid grid-cols-3 gap-3">
        <Stat label={t("cellar.stats.vessels")} value={vessels.data?.length ?? 0} />
        <Stat label={t("cellar.stats.activeLots")} value={lots.data?.meta?.total ?? lots.data?.data.length ?? 0} />
        <Stat label={t("cellar.stats.totalVolume")} value={`${Math.round(totalVolume)} L`} />
      </div>

      <Card>
        <CardContent className="p-4">
          <CellarMap />
        </CardContent>
      </Card>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold">{t("cellar.activeLots")}</h2>
        {lots.isLoading ? (
          <div className="flex h-24 items-center justify-center">
            <Spinner />
          </div>
        ) : (
          <WineLotsByVintage lots={lots.data?.data ?? []} />
        )}
      </section>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <Card>
      <CardContent className="p-4">
        <div className="text-2xl font-semibold">{value}</div>
        <div className="text-xs text-muted-foreground">{label}</div>
      </CardContent>
    </Card>
  );
}
