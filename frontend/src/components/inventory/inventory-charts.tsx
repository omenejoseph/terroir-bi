"use client";

import { useInventoryAnalytics } from "@/hooks/use-dashboard";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Spinner } from "@/components/ui/spinner";
import {
  ChartCard,
  DonutChart,
  LowStockChart,
  TopProductsChart,
} from "@/components/dashboard/charts";

const CATEGORY_COLORS = ["var(--color-primary)", "#d6a417", "#3b82f6", "#10b981", "#8b5cf6"];

/** Analytics charts shown at the top of the inventory page. Server-optimised data. */
export function InventoryCharts() {
  const { t } = useTranslation();
  const { data, isLoading } = useInventoryAnalytics();
  const { number: fmtNum, money } = useFormatters();

  if (isLoading || !data) {
    return (
      <div className="flex items-center justify-center py-10">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  const stockLevels = data.stock_levels.map((s) => ({ name: s.name, value: Number(s.stock) }));
  const categoryData = data.value.categories.map((c, i) => ({
    key: t(`inventory.category.${c.category}`),
    value: c.value,
    color: CATEGORY_COLORS[i % CATEGORY_COLORS.length],
  }));
  const hasLowStock = data.low_stock.below.length + data.low_stock.approaching.length > 0;

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <ChartCard title={t("inventory.analytics.stockLevels")} delayMs={60}>
        <TopProductsChart data={stockLevels} formatValue={(n) => fmtNum(n)} />
      </ChartCard>

      <ChartCard title={t("inventory.analytics.valueByCategory")} delayMs={120}>
        <DonutChart
          data={categoryData}
          centerValue={money(data.value.total)}
          centerLabel={t("inventory.analytics.total")}
        />
        <ul className="mt-2 space-y-1.5">
          {categoryData.map((c) => (
            <li key={c.key} className="flex items-center justify-between text-sm">
              <span className="flex items-center gap-2">
                <span className="size-2.5 rounded-full" style={{ backgroundColor: c.color }} />
                {c.key}
              </span>
              <span className="font-medium tabular-nums">{money(c.value)}</span>
            </li>
          ))}
        </ul>
      </ChartCard>

      <ChartCard title={t("inventory.analytics.lowStock")} delayMs={180}>
        {hasLowStock ? (
          <>
            <LowStockChart below={data.low_stock.below} approaching={data.low_stock.approaching} />
            <div className="mt-2 flex flex-wrap gap-4 text-xs text-muted-foreground">
              <span className="flex items-center gap-1.5">
                <span className="size-2.5 rounded-full bg-destructive" />
                {t("inventory.analytics.belowMin")}
              </span>
              <span className="flex items-center gap-1.5">
                <span className="size-2.5 rounded-full" style={{ backgroundColor: "#d6a417" }} />
                {t("inventory.analytics.approachingMin")}
              </span>
            </div>
          </>
        ) : (
          <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">
            {t("inventory.analytics.allHealthy")}
          </div>
        )}
      </ChartCard>
    </div>
  );
}