import { cn } from "@/lib/utils";

/**
 * A compact labelled value for list-card summaries: a small uppercase label
 * followed by a bold value. Shared across the inventory/orders/customers (etc.)
 * cards so they present key fields consistently.
 */
export function MetaField({
  label,
  children,
  className,
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <span className={cn("inline-flex items-baseline gap-1.5 text-sm", className)}>
      <span className="text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
      <span className="font-bold tabular-nums text-foreground">{children}</span>
    </span>
  );
}