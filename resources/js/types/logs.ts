/**
 * One entry in a tenant's own audit trail (LogController) — same shape as
 * Admin\AuditLog (types/admin.ts), kept as its own type rather than shared so
 * the tenant and platform-admin surfaces stay decoupled.
 */
export interface TenantAuditLog {
    id: string;
    actor_name: string | null;
    action: string;
    subject_type: string | null;
    subject_id: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}
