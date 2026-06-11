"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useBottleAnalyses, useInventoryItem } from "@/hooks/use-inventory";
import { useInventoryDocuments, useInventoryImages } from "@/hooks/use-inventory-media";
import { useTranslation } from "@/i18n/context";
import { withCount } from "@/lib/labels";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { ItemOverviewSection } from "@/components/inventory/item-overview-section";
import { StockTab } from "@/components/inventory/stock-tab";
import { RecipeSection } from "@/components/inventory/recipe-section";
import { ProduceSection } from "@/components/inventory/produce-section";
import { PricingTab } from "@/components/inventory/pricing-tab";
import { AnalysisSection } from "@/components/inventory/analysis-section";
import { ImagesSection } from "@/components/inventory/images-section";
import { DocumentsSection } from "@/components/inventory/documents-section";

type DetailTab =
  | "overview"
  | "pricing"
  | "stock"
  | "recipe"
  | "produce"
  | "analysis"
  | "images"
  | "documents";

export default function InventoryItemPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("inventory.manage");
  const canPricing = can("pricing.view");

  const [tab, setTab] = React.useState<DetailTab>("overview");
  const itemQ = useInventoryItem(id);
  const item = itemQ.data;

  // Counts shown in the tab labels.
  const analysesQ = useBottleAnalyses(id);
  const imagesQ = useInventoryImages(id ?? "", { enabled: !!id });
  const documentsQ = useInventoryDocuments(id ?? "", { enabled: !!id });

  const tabs = [
    { value: "overview", label: t("inventory.page.overview") },
    ...(canPricing ? [{ value: "pricing", label: t("inventory.pricing.title") }] : []),
    { value: "stock", label: t("inventory.stock.title") },
    { value: "recipe", label: t("inventory.recipe.title") },
    { value: "produce", label: t("inventory.produce.title") },
    { value: "analysis", label: withCount(t("inventory.analysis.title"), analysesQ.data?.length) },
    { value: "images", label: withCount(t("inventory.images.title"), imagesQ.data?.length) },
    { value: "documents", label: withCount(t("inventory.documents.title"), documentsQ.data?.length) },
  ];

  return (
    <div className="space-y-6">
      <Link
        href="/inventory"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("inventory.page.back")}
      </Link>

      {itemQ.isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : itemQ.isError || !item ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("inventory.page.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <header className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">{item.name}</h1>
            <p className="text-sm text-muted-foreground">{item.sku}</p>
          </header>

          <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as DetailTab)} />

          {tab === "overview" && <ItemOverviewSection item={item} canManage={canManage} />}
          {tab === "pricing" && <PricingTab item={item} canManage={canManage} />}
          {tab === "stock" && <StockTab item={item} canManage={canManage} />}
          {tab === "recipe" && <RecipeSection item={item} canManage={canManage} />}
          {tab === "produce" && <ProduceSection item={item} canManage={canManage} />}
          {tab === "analysis" && <AnalysisSection item={item} canManage={canManage} />}
          {tab === "images" && <ImagesSection item={item} canManage={canManage} />}
          {tab === "documents" && <DocumentsSection item={item} canManage={canManage} />}
        </>
      )}
    </div>
  );
}