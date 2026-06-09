/**
 * Helpers for editing money in MAJOR units (e.g. 15.00) while the API speaks
 * integer minor units (1500). Assumes a 2-decimal currency (EUR/USD/HRK→EUR),
 * which is what this app uses.
 */

/** Integer minor units → editable major-unit string; "" when null/undefined. */
export function minorToMajorInput(minor: number | null | undefined): string {
  if (minor == null) return "";
  return String(minor / 100);
}

/** Major-unit input string → integer minor units; null when blank/invalid. */
export function majorToMinor(value: string): number | null {
  const trimmed = value.trim();
  if (trimmed === "") return null;
  const n = Number(trimmed);
  if (!Number.isFinite(n)) return null;
  return Math.round(n * 100);
}
