import { cn } from "@/lib/utils";

/**
 * A pulsing placeholder block. Compose these to mirror a screen's layout so a
 * loading view reads as "content is coming" rather than "nothing happened".
 */
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn("animate-pulse rounded-md bg-muted", className)} aria-hidden />;
}
