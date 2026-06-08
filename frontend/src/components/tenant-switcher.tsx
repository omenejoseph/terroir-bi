"use client";

import * as React from "react";
import { Check, ChevronsUpDown } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import { Spinner } from "@/components/ui/spinner";

/**
 * Tenant switcher. Switching swaps the Bearer token for a new tenant-bound one
 * (handled in the auth context) and refetches everything via React Query reset.
 */
export function TenantSwitcher() {
  const { tenants, activeTenantId, switchTenant } = useAuth();
  const { t } = useTranslation();
  const [open, setOpen] = React.useState(false);
  const [pending, setPending] = React.useState<string | null>(null);

  const active = tenants.find((t) => t.tenant_id === activeTenantId);

  async function handleSwitch(tenantId: string) {
    if (tenantId === activeTenantId) {
      setOpen(false);
      return;
    }
    setPending(tenantId);
    try {
      await switchTenant(tenantId);
      // Full reload is the simplest correct reset of all cached tenant-scoped data.
      window.location.reload();
    } finally {
      setPending(null);
      setOpen(false);
    }
  }

  if (tenants.length === 0) return null;

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm hover:bg-accent"
      >
        <span className="truncate font-medium">{active?.name ?? t("tenant.select")}</span>
        <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
          <ul className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border border-border bg-popover p-1 shadow-md">
            {tenants.map((tenant) => (
              <li key={tenant.tenant_id}>
                <button
                  type="button"
                  onClick={() => handleSwitch(tenant.tenant_id)}
                  className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent"
                >
                  {pending === tenant.tenant_id ? (
                    <Spinner className="size-4" />
                  ) : (
                    <Check
                      className={cn(
                        "size-4",
                        tenant.tenant_id === activeTenantId ? "opacity-100" : "opacity-0",
                      )}
                    />
                  )}
                  <span className="truncate">{tenant.name}</span>
                </button>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}