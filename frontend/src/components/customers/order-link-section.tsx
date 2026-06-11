"use client";

import * as React from "react";
import { Copy, Check, Link2, RefreshCw } from "lucide-react";

import {
  useCustomerToken,
  useGenerateOrderToken,
  useRevokeOrderToken,
} from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import type { Customer } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

export function OrderLinkSection({ customer }: { customer: Customer }) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  // Drive the panel from the token query itself (not the possibly-stale customer
  // prop), so generate/revoke reflect immediately regardless of where the
  // customer came from (list card vs. detail page).
  const tokenQ = useCustomerToken(customer.id);
  const generate = useGenerateOrderToken(customer.id);
  const revoke = useRevokeOrderToken(customer.id);
  const [copied, setCopied] = React.useState(false);

  const token = tokenQ.data?.order_token ?? null;
  const url =
    token && typeof window !== "undefined" ? `${window.location.origin}/order/${token}` : null;

  async function copy() {
    if (!url) return;
    await navigator.clipboard.writeText(url);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  async function onRevoke() {
    const ok = await confirm({
      title: t("customers.orderLink.revokeTitle"),
      description: t("customers.orderLink.revokeBody", { name: customer.company_name }),
      confirmLabel: t("customers.orderLink.revoke"),
      tone: "danger",
    });
    if (ok) await revoke.mutateAsync();
  }

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <div className="flex items-center gap-2">
          <Link2 className="size-4 text-muted-foreground" />
          <h3 className="text-sm font-semibold">{t("customers.orderLink.title")}</h3>
        </div>
        <p className="text-sm text-muted-foreground">{t("customers.orderLink.intro")}</p>

        {tokenQ.isLoading ? (
          <div className="flex py-2">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : token ? (
          <>
            <div className="flex items-center gap-2">
              <Input
                readOnly
                value={url ?? t("customers.orderLink.loading")}
                aria-label={t("customers.orderLink.title")}
                className="font-mono text-xs"
              />
              <Button type="button" variant="outline" size="sm" onClick={copy} disabled={!url}>
                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                {copied ? t("customers.orderLink.copied") : t("customers.orderLink.copy")}
              </Button>
            </div>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => generate.mutate()}
                disabled={generate.isPending}
              >
                {generate.isPending ? <Spinner /> : <RefreshCw className="size-4" />}
                {t("customers.orderLink.regenerate")}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onRevoke}
                disabled={revoke.isPending}
                className="text-destructive hover:text-destructive"
              >
                {t("customers.orderLink.revoke")}
              </Button>
            </div>
          </>
        ) : (
          <Button type="button" onClick={() => generate.mutate()} disabled={generate.isPending}>
            {generate.isPending && <Spinner />}
            {t("customers.orderLink.generate")}
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
