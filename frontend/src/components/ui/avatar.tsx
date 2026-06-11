import { cn } from "@/lib/utils";

/** First letters of the first two words, e.g. "Hiram Richards" → "HR". */
export function initials(name?: string | null): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "")).toUpperCase() || "?";
}

/** A small circular initials badge. */
export function Avatar({ name, className }: { name?: string | null; className?: string }) {
  return (
    <div
      title={name ?? undefined}
      className={cn(
        "flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary",
        className,
      )}
    >
      {initials(name)}
    </div>
  );
}
