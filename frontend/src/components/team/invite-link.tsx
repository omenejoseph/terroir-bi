"use client";

import * as React from "react";
import { Check, Copy } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/** Read-only invitation accept link with a copy button. */
export function InviteLink({ token }: { token: string }) {
  const { t } = useTranslation();
  const [copied, setCopied] = React.useState(false);

  const link = `${typeof window !== "undefined" ? window.location.origin : ""}/accept?token=${token}`;

  async function copy() {
    try {
      await navigator.clipboard.writeText(link);
      setCopied(true);
    } catch {
      // Clipboard may be unavailable; the link is still selectable.
    }
  }

  return (
    <div className="flex items-center gap-2">
      <Input readOnly value={link} aria-label={t("team.inviteLink")} className="font-mono text-xs" />
      <Button type="button" variant="outline" onClick={copy} className="shrink-0">
        {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
        {copied ? t("team.copied") : t("team.copy")}
      </Button>
    </div>
  );
}