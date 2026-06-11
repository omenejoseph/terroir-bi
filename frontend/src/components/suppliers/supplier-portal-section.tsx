"use client";

import * as React from "react";
import { Check, Copy, ExternalLink, Link2, RefreshCw } from "lucide-react";

import {
  useGenerateSupplierPortalToken,
  useRevokeSupplierPortalToken,
  useSupplierPortalToken,
} from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import type { Supplier } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

export function SupplierPortalSection({ supplier }: { supplier: Supplier }) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const tokenQ = useSupplierPortalToken(supplier.id);
  const generate = useGenerateSupplierPortalToken(supplier.id);
  const revoke = useRevokeSupplierPortalToken(supplier.id);
  const [copied, setCopied] = React.useState(false);

  const token = tokenQ.data?.portal_token ?? null;
  const url =
    token && typeof window !== "undefined"
      ? `${window.location.origin}/supplier-portal/${token}`
      : null;

  async function copy() {
    if (!url) return;
    await navigator.clipboard.writeText(url);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  async function onDisable() {
    const ok = await confirm({
      title: t("suppliers.portal.disableTitle"),
      description: t("suppliers.portal.disableBody", { name: supplier.company_name }),
      confirmLabel: t("suppliers.portal.disable"),
      tone: "danger",
    });
    if (ok) await revoke.mutateAsync();
  }

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <Link2 className="size-4 text-muted-foreground" />
            <h3 className="text-sm font-semibold">{t("suppliers.portal.title")}</h3>
          </div>
          {token != null && (
            <span className="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
              {t("suppliers.portal.enabled")}
            </span>
          )}
        </div>
        <p className="text-sm text-muted-foreground">{t("suppliers.portal.intro")}</p>

        {tokenQ.isLoading ? (
          <div className="flex py-2">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : token ? (
          <>
            <div className="flex items-center gap-2">
              <Input
                readOnly
                value={url ?? ""}
                aria-label={t("suppliers.portal.link")}
                className="font-mono text-xs"
              />
              <Button type="button" variant="outline" size="sm" onClick={copy} disabled={!url}>
                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                {copied ? t("suppliers.portal.copied") : t("suppliers.portal.copy")}
              </Button>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => url && window.open(url, "_blank", "noopener")}
                disabled={!url}
              >
                <ExternalLink className="size-4" />
                {t("suppliers.portal.open")}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => generate.mutate()}
                disabled={generate.isPending}
              >
                {generate.isPending ? <Spinner /> : <RefreshCw className="size-4" />}
                {t("suppliers.portal.regenerate")}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onDisable}
                disabled={revoke.isPending}
                className="text-destructive hover:text-destructive"
              >
                {t("suppliers.portal.disable")}
              </Button>
            </div>
          </>
        ) : (
          <Button type="button" onClick={() => generate.mutate()} disabled={generate.isPending}>
            {generate.isPending && <Spinner />}
            {t("suppliers.portal.enable")}
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
