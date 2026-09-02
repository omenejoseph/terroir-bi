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

/**
 * One tab in a `Tabs` strip.
 *
 * `href` navigates, `value` emits `select`, and neither means the destination is
 * designed but not built yet — it renders disabled rather than as a dead link.
 */
export interface TabItem {
    label: string;
    href?: string | null;
    value?: string;
}
