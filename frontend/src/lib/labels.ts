/** Append a non-zero count to a tab/label, e.g. `Images (3)`. */
export function withCount(label: string, count: number | undefined): string {
  return count ? `${label} (${count})` : label;
}
