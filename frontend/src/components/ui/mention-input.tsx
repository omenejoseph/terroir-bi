"use client";

import * as React from "react";
import { createPortal } from "react-dom";

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
  const [pos, setPos] = React.useState<{
    top: number;
    left: number;
    width: number;
    maxHeight: number;
  } | null>(null);

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
    const caret = taRef.current?.selectionStart ?? value.length;
    const before = value.slice(0, anchor);
    const after = value.slice(caret);
    const insert = `@${m.name} `;
    const next = before + insert + after;
    mentioned.current.set(m.user_id, m.name);
    pendingCaret.current = before.length + insert.length;
    onChange(next);
    setQuery(null);
    emitMentions(next);
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

  // Anchor the dropdown under the textarea via a portal so it never clips.
  const updatePosition = React.useCallback(() => {
    const el = taRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom - 8;
    setPos({ top: r.bottom + 4, left: r.left, width: r.width, maxHeight: Math.max(140, spaceBelow) });
  }, []);

  React.useLayoutEffect(() => {
    if (!open) return;
    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);
    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [open, updatePosition]);

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
        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
      />
      {open &&
        pos &&
        createPortal(
          <ul
            role="listbox"
            className="fixed z-50 overflow-auto rounded-md border border-border bg-popover p-1 shadow-md"
            style={{ top: pos.top, left: pos.left, width: pos.width, maxHeight: pos.maxHeight }}
          >
            {filtered.map((m, i) => (
              <li key={m.user_id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={i === highlight}
                  // mousedown fires before the textarea blur, so selection still lands.
                  onMouseDown={(e) => {
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
          </ul>,
          document.body,
        )}
    </div>
  );
}
