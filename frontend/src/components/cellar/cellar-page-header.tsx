"use client";

import * as React from "react";

import { useTranslation } from "@/i18n/context";
import { Breadcrumb } from "@/components/ui/breadcrumb";
import { CellarSubnav } from "@/components/cellar/cellar-subnav";

/** Breadcrumb (Cellar / {title}) + cellar sub-nav + page title, for sub-pages. */
export function CellarPageHeader({
  title,
  subtitle,
  actions,
}: {
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
}) {
  const { t } = useTranslation();

  return (
    <div className="space-y-4">
      <Breadcrumb items={[{ label: t("cellar.title"), href: "/cellar" }, { label: title }]} />
      <CellarSubnav />
      <header className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{title}</h1>
          {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
        </div>
        {actions}
      </header>
    </div>
  );
}
