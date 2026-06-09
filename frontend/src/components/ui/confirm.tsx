"use client";

import * as React from "react";

import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";

export interface ConfirmOptions {
  title: string;
  description?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** "danger" renders the confirm button in the destructive style. */
  tone?: "default" | "danger";
}

type Confirm = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = React.createContext<Confirm | null>(null);

/**
 * App-wide confirmation prompt. Mount once near the root, then call the
 * `useConfirm()` hook imperatively:
 *
 *   const confirm = useConfirm();
 *   if (await confirm({ title, description, tone: "danger" })) { …do it }
 *
 * Keeps the "are you sure?" wiring in one place so every dangerous action
 * (activate / deactivate / delete) guards the same way.
 */
export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const { t } = useTranslation();
  const [options, setOptions] = React.useState<ConfirmOptions | null>(null);
  const resolver = React.useRef<((value: boolean) => void) | null>(null);

  const confirm = React.useCallback<Confirm>((opts) => {
    setOptions(opts);
    return new Promise<boolean>((resolve) => {
      resolver.current = resolve;
    });
  }, []);

  const settle = React.useCallback((result: boolean) => {
    resolver.current?.(result);
    resolver.current = null;
    setOptions(null);
  }, []);

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <Dialog
        open={options !== null}
        onOpenChange={(open) => {
          if (!open) settle(false);
        }}
        title={options?.title ?? ""}
        description={options?.description}
        className="max-w-md"
      >
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={() => settle(false)}>
            {options?.cancelLabel ?? t("common.confirm.cancel")}
          </Button>
          <Button
            variant={options?.tone === "danger" ? "destructive" : "primary"}
            onClick={() => settle(true)}
          >
            {options?.confirmLabel ?? t("common.confirm.confirm")}
          </Button>
        </div>
      </Dialog>
    </ConfirmContext.Provider>
  );
}

export function useConfirm(): Confirm {
  const ctx = React.useContext(ConfirmContext);
  if (!ctx) throw new Error("useConfirm must be used within <ConfirmProvider>");
  return ctx;
}
