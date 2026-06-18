import type { WineLotStatus, WineType } from "@/lib/types";

/**
 * Visual tokens for the cellar. Wine-type fill colors are intentionally literal
 * hex values (the cellar-map liquid fill is drawn directly, not via Tailwind
 * classes) so a tank reads as its wine at a glance. Status maps onto the shared
 * Badge variants so it inherits the design system.
 */

const WINE_TYPE_FILL: Record<WineType, string> = {
  RED: "#6b2138",
  WHITE: "#c4a43c",
  ROSE: "#d4728a",
  ORANGE: "#c9772e",
  SPARKLING: "#e8d9a0",
  DESSERT: "#7a4a1e",
};

const DEFAULT_FILL = "#8a8f98";

export function wineFill(wineType: WineType | null | undefined): string {
  return wineType ? WINE_TYPE_FILL[wineType] : DEFAULT_FILL;
}

export type BadgeVariant =
  | "default"
  | "secondary"
  | "outline"
  | "destructive"
  | "info"
  | "warning"
  | "purple"
  | "success";

export function lotStatusVariant(status: WineLotStatus): BadgeVariant {
  switch (status) {
    case "FERMENTING":
      return "warning";
    case "AGING":
      return "purple";
    case "READY":
      return "success";
    case "BOTTLED":
      return "info";
    case "BLENDED":
      return "secondary";
    default:
      return "default";
  }
}

/** SO₂ health color (free SO₂ mg/L): red < 15, amber 15–25, green 25–45, blue > 45. */
export function so2Color(free: number | null): string {
  if (free === null) return "#9ca3af";
  if (free < 15) return "#dc2626";
  if (free < 25) return "#d97706";
  if (free <= 45) return "#16a34a";
  return "#2563eb";
}

/** 0–1 fill ratio from current/capacity strings. */
export function fillRatio(current: string | number, capacity: string | number): number {
  const c = Number(current);
  const cap = Number(capacity);
  if (!Number.isFinite(c) || !Number.isFinite(cap) || cap <= 0) return 0;
  return Math.max(0, Math.min(1, c / cap));
}
