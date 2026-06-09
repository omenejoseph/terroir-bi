"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useInventoryItem } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { ItemOverviewSection } from "@/components/inventory/item-overview-section";
import { StockSection } from "@/components/inventory/stock-section";
import { RecipeSection } from "@/components/inventory/recipe-section";
import { ImagesSection } from "@/components/inventory/images-section";

type DetailTab = "overview" | "stock" | "recipe" | "images";

export default function InventoryItemPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("inventory.manage");

  const [tab, setTab] = React.useState<DetailTab>("overview");
  const itemQ = useInventoryItem(id);
  const item = itemQ.data;

  const tabs = [
    { value: "overview", label: t("inventory.page.overview") },
    { value: "stock", label: t("inventory.movements.title") },
    { value: "recipe", label: t("inventory.recipe.title") },
    { value: "images", label: t("inventory.images.title") },
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
          {tab === "stock" && <StockSection item={item} canManage={canManage} />}
          {tab === "recipe" && <RecipeSection item={item} canManage={canManage} />}
          {tab === "images" && <ImagesSection item={item} canManage={canManage} />}
        </>
      )}
    </div>
  );
}