import { Info } from "lucide-react";

/**
 * A small info icon that explains a field on hover/focus. The text is always in
 * the DOM (sr-only + native title) so it's accessible and assertable in tests.
 */
export function InfoHint({ text }: { text: string }) {
  return (
    <span className="group relative inline-flex align-middle" title={text}>
      <Info className="size-3.5 cursor-help text-muted-foreground" aria-label={text} />
      <span
        role="tooltip"
        className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1.5 w-56 -translate-x-1/2 rounded-md bg-popover px-2.5 py-1.5 text-xs leading-snug text-popover-foreground opacity-0 shadow-md ring-1 ring-border transition-opacity duration-150 group-hover:opacity-100"
      >
        {text}
      </span>
    </span>
  );
}
