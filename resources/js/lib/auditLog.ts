/**
 * Shared rendering for both audit-log tables (Logs/Index.vue — a tenant's own
 * trail, and Admin/AuditLogs/Index.vue — platform-wide). The two pages keep
 * separate types/components by design (see Logs/Index.vue's own docblock),
 * but there's nothing tenant-specific about turning an action string or a
 * metadata bag into something readable, so that part is shared.
 */

/**
 * "order.status_changed" -> "Order status changed". Every action string this
 * app writes follows App\Services\Audit\Auditable's own "{subject}.{event}"
 * convention (auditAction()), including the hand-named ones outside that
 * trait (e.g. "user.password_reset_sent") — so a single split-and-title-case
 * reads all of them, with no per-action mapping to keep in sync. A few (the
 * impersonation pair) carry a third segment — "user.impersonation.started" —
 * so every dot is replaced, not just the first.
 */
export function formatActionLabel(action: string): string {
    const words = action.replaceAll('.', ' ').replaceAll('_', ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * Metadata has no fixed shape — it's whatever the call site thought was
 * worth recording (a `{ changed: {...} }` bag from Auditable's automatic
 * floor, or a hand-picked set of fields from an explicit AuditLogger call).
 * Rather than a renderer per action (which silently stops covering new
 * actions), this reads every metadata bag the same generic way: unwrap
 * Auditable's own "changed" envelope if present, then list each field as
 * "label: value", objects/arrays JSON-stringified.
 */
export function formatMetadata(metadata: Record<string, unknown> | null): string {
    if (metadata === null) return '—';

    const changed = metadata.changed;
    const source = typeof changed === 'object' && changed !== null && !Array.isArray(changed) ? (changed as Record<string, unknown>) : metadata;

    const parts = Object.entries(source).map(([key, value]) => `${key.replace(/_/g, ' ')}: ${formatValue(value)}`);

    return parts.length > 0 ? parts.join(' · ') : '—';
}

function formatValue(value: unknown): string {
    if (value === null) return '—';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
}
