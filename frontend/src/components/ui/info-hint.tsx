import { Info } from "lucide-react";

/**
 * A small info icon that explains a field on hover/focus. The text is always in
 * the DOM (sr-only + native title) so it's accessible and assertable in tests.
 * An optional `detail` line (e.g. a recommendation/benchmark) renders muted &
 * italic beneath the main text, mirroring the prototype's KPI info popover.
 */
export function InfoHint({ text, detail }: { text: string; detail?: string }) {
  const title = detail ? `${text}\n\n${detail}` : text;

  return (
    <span className="group relative inline-flex align-middle" title={title}>
      <Info className="size-3.5 cursor-help text-muted-foreground" aria-label={title} />
      <span
        role="tooltip"
        className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1.5 w-60 -translate-x-1/2 space-y-1 rounded-md bg-popover px-2.5 py-1.5 text-left text-xs leading-snug text-popover-foreground opacity-0 shadow-md ring-1 ring-border transition-opacity duration-150 group-hover:opacity-100"
      >
        <span className="block text-popover-foreground">{text}</span>
        {detail && <span className="block italic text-muted-foreground">{detail}</span>}
      </span>
    </span>
  );
}
