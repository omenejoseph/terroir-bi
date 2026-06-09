"use client";

import * as React from "react";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Minimal controlled modal dialog — no external dependency, token-styled.
 * Closes on Escape and overlay click. For richer needs, swap in shadcn's
 * Radix-based dialog; the API (open / onOpenChange) is intentionally similar.
 */
export function Dialog({
  open,
  onOpenChange,
  title,
  description,
  children,
  className,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  children: React.ReactNode;
  className?: string;
}) {
  React.useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onOpenChange(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onOpenChange]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      <div className="absolute inset-0 bg-black/50" onClick={() => onOpenChange(false)} aria-hidden />
      <div
        className={cn(
          "relative z-10 w-full max-w-lg rounded-xl border border-border bg-card text-card-foreground shadow-lg",
          "max-h-[90dvh] overflow-y-auto",
          className,
        )}
      >
        <div className="flex items-start justify-between gap-4 p-6 pb-2">
          <div className="space-y-1">
            <h2 className="text-lg font-semibold leading-none tracking-tight">{title}</h2>
            {description && <p className="text-sm text-muted-foreground">{description}</p>}
          </div>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="rounded-sm opacity-70 transition-opacity hover:opacity-100"
            aria-label="close"
          >
            <X className="size-5" />
          </button>
        </div>
        <div className="p-6 pt-2">{children}</div>
      </div>
    </div>
  );
}