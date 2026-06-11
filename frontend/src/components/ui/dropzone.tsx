"use client";

import * as React from "react";
import { UploadCloud } from "lucide-react";

import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";

/**
 * Shared file-drop entry point: drag a file onto it or click to browse. Used by
 * both the image and document uploaders so the pick experience is consistent.
 * The caller decides what happens with the file (open an editor, upload, …).
 */
export function Dropzone({
  accept,
  onFile,
  inputLabel,
  title,
  hint,
  busy = false,
  disabled = false,
}: {
  accept: string;
  onFile: (file: File) => void;
  /** Accessible name for the hidden file input. */
  inputLabel: string;
  /** Visible call-to-action text. */
  title: string;
  hint?: string;
  busy?: boolean;
  disabled?: boolean;
}) {
  const inputRef = React.useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = React.useState(false);
  const blocked = busy || disabled;

  function take(file: File | undefined) {
    if (file && !blocked) onFile(file);
  }

  return (
    <div
      onClick={() => !blocked && inputRef.current?.click()}
      onDragOver={(e) => {
        e.preventDefault();
        if (!blocked) setDragging(true);
      }}
      onDragLeave={() => setDragging(false)}
      onDrop={(e) => {
        e.preventDefault();
        setDragging(false);
        take(e.dataTransfer.files?.[0]);
      }}
      className={cn(
        "flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center transition-colors",
        blocked
          ? "cursor-not-allowed border-border opacity-70"
          : "cursor-pointer border-border hover:border-primary/50 hover:bg-muted/40",
        dragging && "border-primary bg-primary/5",
      )}
    >
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        aria-label={inputLabel}
        className="hidden"
        disabled={blocked}
        onChange={(e) => {
          take(e.target.files?.[0]);
          e.target.value = ""; // allow re-picking the same file
        }}
      />
      {busy ? (
        <Spinner className="size-5 text-muted-foreground" />
      ) : (
        <UploadCloud className="size-6 text-muted-foreground" />
      )}
      <p className="text-sm font-medium">{title}</p>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}
