"use client";

import * as React from "react";
import { ArrowLeftRight, Check } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import type { TenantMembership } from "@/lib/types";
import { cn } from "@/lib/utils";
import { Spinner } from "@/components/ui/spinner";

function initials(name?: string): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "")).toUpperCase() || "?";
}

/**
 * Shows the active organisation (logo tile + name) under the app logo. It is NOT
 * a dropdown by default — a dedicated switch icon reveals the fancy org picker.
 * Switching swaps the tenant-bound token (auth context) and reloads to reset all
 * tenant-scoped caches.
 */
export function TenantSwitcher({ compact = false }: { compact?: boolean }) {
  const { tenants, activeTenantId, switchTenant } = useAuth();
  const { t } = useTranslation();
  const [open, setOpen] = React.useState(false);
  const [pending, setPending] = React.useState<string | null>(null);
  const containerRef = React.useRef<HTMLDivElement>(null);

  const active = tenants.find((x) => x.tenant_id === activeTenantId);

  React.useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

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

  // Compact (collapsed rail): just the org avatar, which opens the picker.
  if (compact) {
    return (
      <div ref={containerRef} className="relative flex justify-center">
        <button
          type="button"
          aria-label={t("tenant.switch")}
          onClick={() => setOpen((o) => !o)}
          className="flex size-9 items-center justify-center rounded-md bg-gradient-to-br from-primary to-primary/70 text-xs font-semibold text-primary-foreground shadow-sm transition-transform hover:scale-105"
        >
          {initials(active?.name)}
        </button>
        {open && (
          <OrgMenu
            className="left-full top-0 ml-2 w-64"
            tenants={tenants}
            activeTenantId={activeTenantId}
            pending={pending}
            label={t("tenant.switcherLabel")}
            onSelect={handleSwitch}
          />
        )}
      </div>
    );
  }

  return (
    <div ref={containerRef} className="relative">
      {/* Active org display — static, with a switch affordance. */}
      <div className="flex items-center gap-2.5 rounded-lg border border-border bg-background/60 p-2 shadow-sm">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-primary to-primary/70 text-xs font-semibold text-primary-foreground shadow-sm">
          {initials(active?.name)}
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold leading-tight">
            {active?.name ?? t("tenant.select")}
          </p>
          {active?.slug && (
            <p className="truncate text-xs text-muted-foreground">{active.slug}</p>
          )}
        </div>
        <button
          type="button"
          aria-label={t("tenant.switch")}
          onClick={() => setOpen((o) => !o)}
          className={cn(
            "flex size-7 shrink-0 items-center justify-center rounded-md transition-colors",
            open ? "bg-primary/10 text-primary" : "text-muted-foreground hover:bg-accent hover:text-foreground",
          )}
        >
          <ArrowLeftRight className="size-4" />
        </button>
      </div>

      {/* Fancy org picker */}
      {open && (
        <OrgMenu
          className="inset-x-0 top-full mt-2"
          tenants={tenants}
          activeTenantId={activeTenantId}
          pending={pending}
          label={t("tenant.switcherLabel")}
          onSelect={handleSwitch}
        />
      )}
    </div>
  );
}

function OrgMenu({
  className,
  tenants,
  activeTenantId,
  pending,
  label,
  onSelect,
}: {
  className?: string;
  tenants: TenantMembership[];
  activeTenantId: string | null;
  pending: string | null;
  label: string;
  onSelect: (tenantId: string) => void;
}) {
  return (
    <div
      className={cn(
        "absolute z-30 origin-top animate-scale-in overflow-hidden rounded-xl border border-border bg-popover p-1.5 shadow-xl",
        className,
      )}
    >
      <p className="px-2 py-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <ul className="space-y-0.5">
        {tenants.map((tenant) => {
          const isActive = tenant.tenant_id === activeTenantId;
          return (
            <li key={tenant.tenant_id}>
              <button
                type="button"
                onClick={() => onSelect(tenant.tenant_id)}
                className={cn(
                  "flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors",
                  isActive ? "bg-primary/10" : "hover:bg-accent",
                )}
              >
                <span
                  className={cn(
                    "flex size-8 shrink-0 items-center justify-center rounded-md text-xs font-semibold shadow-sm",
                    isActive
                      ? "bg-gradient-to-br from-primary to-primary/70 text-primary-foreground"
                      : "bg-muted text-muted-foreground",
                  )}
                >
                  {initials(tenant.name)}
                </span>
                <div className="min-w-0 flex-1">
                  <p
                    className={cn(
                      "truncate text-sm font-medium leading-tight",
                      isActive && "text-primary",
                    )}
                  >
                    {tenant.name}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">{tenant.slug}</p>
                </div>
                {pending === tenant.tenant_id ? (
                  <Spinner className="size-4 text-muted-foreground" />
                ) : isActive ? (
                  <Check className="size-4 text-primary" />
                ) : null}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}