"use client";

import * as React from "react";

import { cn } from "@/lib/utils";

export interface MentionMember {
  user_id: string;
  name: string;
  email?: string;
}

const MAX_RESULTS = 8;

/**
 * A textarea with inline `@`-mention autocomplete. Typing `@` (at the start or
 * after whitespace) opens a portal dropdown of team members; arrow keys move,
 * Enter/Tab selects, Escape dismisses. Selecting inserts `@Name ` into the text
 * and reports the set of mentioned user_ids (those whose token is still present)
 * via onMentionsChange.
 */
export function MentionInput({
  value,
  onChange,
  onMentionsChange,
  members,
  placeholder,
  disabled,
  rows = 3,
  id,
  "aria-label": ariaLabel,
}: {
  value: string;
  onChange: (value: string) => void;
  onMentionsChange?: (userIds: string[]) => void;
  members: MentionMember[];
  placeholder?: string;
  disabled?: boolean;
  rows?: number;
  id?: string;
  "aria-label"?: string;
}) {
  const taRef = React.useRef<HTMLTextAreaElement>(null);
  const mentioned = React.useRef<Map<string, string>>(new Map()); // user_id -> inserted name
  const pendingCaret = React.useRef<number | null>(null);

  const [query, setQuery] = React.useState<string | null>(null);
  const [anchor, setAnchor] = React.useState(0);
  const [highlight, setHighlight] = React.useState(0);
  const [placement, setPlacement] = React.useState<{ above: boolean; maxHeight: number }>({
    above: false,
    maxHeight: 280,
  });

  const filtered = React.useMemo(() => {
    if (query === null) return [];
    const q = query.toLowerCase();
    return members
      .filter((m) => m.name.toLowerCase().includes(q) || (m.email ?? "").toLowerCase().includes(q))
      .slice(0, MAX_RESULTS);
  }, [query, members]);

  const open = query !== null && filtered.length > 0;

  // Detect an active "@token" immediately before the caret (no whitespace in it).
  function detect(text: string, caret: number) {
    const upto = text.slice(0, caret);
    const at = upto.lastIndexOf("@");
    if (at === -1) return setQuery(null);
    const before = at === 0 ? "" : upto[at - 1];
    const token = upto.slice(at + 1);
    if ((at === 0 || /\s/.test(before)) && !/\s/.test(token)) {
      setAnchor(at);
      setQuery(token);
      setHighlight(0);
    } else {
      setQuery(null);
    }
  }

  function emitMentions(text: string) {
    if (!onMentionsChange) return;
    const ids = new Set<string>();
    for (const [uid, name] of mentioned.current) {
      if (text.includes(`@${name}`)) ids.add(uid);
    }
    onMentionsChange([...ids]);
  }

  function handleChange(e: React.ChangeEvent<HTMLTextAreaElement>) {
    const text = e.target.value;
    onChange(text);
    detect(text, e.target.selectionStart ?? text.length);
    emitMentions(text);
  }

  function selectMember(m: MentionMember) {
    const ta = taRef.current;
    const caret = ta?.selectionStart ?? value.length;
    const before = value.slice(0, anchor);
    const after = value.slice(caret);
    const insert = `@${m.name} `;
    const next = before + insert + after;
    mentioned.current.set(m.user_id, m.name);
    pendingCaret.current = before.length + insert.length;
    onChange(next);
    setQuery(null);
    emitMentions(next);
    // Refocus synchronously, while still inside the tap gesture. iOS Safari only
    // keeps the keyboard up (and the field focused) for a focus() call made during
    // a user gesture — the async caret-restore effect below runs too late on its
    // own, so on mobile the field would otherwise blur and dismiss the keyboard.
    ta?.focus();
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if (!open) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setHighlight((h) => (h + 1) % filtered.length);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setHighlight((h) => (h - 1 + filtered.length) % filtered.length);
    } else if (e.key === "Enter" || e.key === "Tab") {
      e.preventDefault();
      selectMember(filtered[Math.min(highlight, filtered.length - 1)]);
    } else if (e.key === "Escape") {
      e.preventDefault();
      setQuery(null);
    }
  }

  // Restore the caret after a programmatic insert; clear tracked mentions when emptied.
  React.useEffect(() => {
    if (pendingCaret.current !== null && taRef.current) {
      const c = pendingCaret.current;
      taRef.current.focus();
      taRef.current.setSelectionRange(c, c);
      pendingCaret.current = null;
    }
    if (value === "") mentioned.current.clear();
  }, [value]);

  // The dropdown is an absolutely-positioned child of the field's wrapper, so it
  // is glued to the textarea's own edges — no viewport coordinate math, immune
  // to the mobile keyboard shifting the visual viewport (which is what made a
  // portaled `position: fixed` list drift a scroll away from the field on iOS).
  // The only thing we measure is *direction*: flip the list above the field when
  // there isn't room below it within the visible (keyboard-shrunk) viewport —
  // otherwise a bottom-anchored comment box would render it behind the keyboard.
  const updatePlacement = React.useCallback(() => {
    const el = taRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const vv = window.visualViewport;
    const offTop = vv?.offsetTop ?? 0;
    const viewHeight = vv?.height ?? window.innerHeight;
    const spaceBelow = viewHeight - (r.bottom - offTop) - 8;
    const spaceAbove = r.top - offTop - 8;
    const above = spaceBelow < 160 && spaceAbove > spaceBelow;
    const maxHeight = Math.min(280, Math.max(120, above ? spaceAbove : spaceBelow));
    setPlacement({ above, maxHeight });
  }, []);

  React.useLayoutEffect(() => {
    if (!open) return;
    updatePlacement();
    window.addEventListener("resize", updatePlacement);
    // The visual viewport fires these when the keyboard opens/closes or the page
    // is pinch-zoomed; re-evaluate which side has room through all of it.
    window.visualViewport?.addEventListener("resize", updatePlacement);
    window.visualViewport?.addEventListener("scroll", updatePlacement);
    return () => {
      window.removeEventListener("resize", updatePlacement);
      window.visualViewport?.removeEventListener("resize", updatePlacement);
      window.visualViewport?.removeEventListener("scroll", updatePlacement);
    };
  }, [open, updatePlacement]);

  return (
    <div className="relative">
      <textarea
        ref={taRef}
        id={id}
        aria-label={ariaLabel}
        rows={rows}
        value={value}
        disabled={disabled}
        placeholder={placeholder}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        onBlur={() => window.setTimeout(() => setQuery(null), 100)}
        // text-base (16px) on mobile avoids iOS Safari's focus auto-zoom; text-sm from md up.
        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
      />
      {open && (
        <ul
          role="listbox"
          className={cn(
            "absolute inset-x-0 z-50 overflow-auto rounded-md border border-border bg-popover p-1 shadow-md",
            placement.above ? "bottom-full mb-1" : "top-full mt-1",
          )}
          style={{ maxHeight: placement.maxHeight }}
        >
          {filtered.map((m, i) => (
            <li key={m.user_id}>
              <button
                type="button"
                role="option"
                aria-selected={i === highlight}
                // pointerdown fires before the textarea blur for mouse AND touch,
                // so the selection still lands; preventDefault keeps focus on the
                // textarea. (mousedown alone is synthesized too late on mobile —
                // the blur timer closes the dropdown before the tap registers.)
                onPointerDown={(e) => {
                  e.preventDefault();
                  selectMember(m);
                }}
                onMouseEnter={() => setHighlight(i)}
                className={cn(
                  "block w-full rounded-sm px-2 py-1.5 text-left text-sm",
                  i === highlight ? "bg-accent" : "hover:bg-accent",
                )}
              >
                <span className="font-medium">{m.name}</span>
                {m.email && <span className="ml-2 text-xs text-muted-foreground">{m.email}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
