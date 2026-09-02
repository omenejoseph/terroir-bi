/**
 * Shapes shared between the UI primitives and the pages that use them.
 *
 * These live here rather than inside the .vue files because a `<script setup>`
 * block cannot contain ES module exports — the SFC compiler rejects it.
 */

/** One chip in the "Needs attention" band (Figma 389:1592). */
export interface AttentionItem {
    key: string;
    label: string;
    count: number;
}

/** A tab in a module's sub-navigation; `href: null` renders disabled. */
export interface Tab {
    label: string;
    href: string | null;
}
