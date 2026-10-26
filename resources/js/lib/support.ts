/**
 * Support tickets (App\Models\SupportTicket) — the shapes
 * App\Services\Support\SupportTicketPresenter sends, and the labels both the
 * tenant pages (/support) and the admin queue (/admin/support) show.
 */

export type SupportTicketStatus = 'open' | 'in_progress' | 'waiting_on_customer' | 'resolved' | 'closed';
export type SupportTicketCategory = 'question' | 'problem' | 'billing' | 'feature_request';
export type SupportTicketPriority = 'low' | 'normal' | 'high' | 'urgent';

export interface SupportTicketRow {
    id: string;
    number: number;
    subject: string;
    category: SupportTicketCategory;
    priority: SupportTicketPriority;
    status: SupportTicketStatus;
    /** Admin queue only. */
    tenant: { id: string; name: string | null } | null;
    requester: string | null;
    assignee: string | null;
    assignee_id: string | null;
    last_message_at: string | null;
    created_at: string | null;
}

export interface SupportTicketMessage {
    id: string;
    body: string;
    author: string | null;
    is_staff: boolean;
    /** Staff-only note — the tenant side never receives these. */
    is_internal: boolean;
    created_at: string | null;
}

export interface SupportTicketDetail extends SupportTicketRow {
    page_url: string | null;
    requester_email: string | null;
    messages: SupportTicketMessage[];
}

/** Translation keys — pass through t(). */
export const STATUS_LABELS: Record<SupportTicketStatus, string> = {
    open: 'Open',
    in_progress: 'In progress',
    waiting_on_customer: 'Waiting for reply',
    resolved: 'Resolved',
    closed: 'Closed',
};

export const CATEGORY_LABELS: Record<SupportTicketCategory, string> = {
    question: 'Question',
    problem: 'Something isn\'t working',
    billing: 'Billing',
    feature_request: 'Feature request',
};

export const PRIORITY_LABELS: Record<SupportTicketPriority, string> = {
    low: 'Low',
    normal: 'Normal',
    high: 'High',
    urgent: 'Urgent',
};

export function statusVariant(status: SupportTicketStatus): 'success' | 'outline' | 'neutral' | 'solid' {
    switch (status) {
        case 'open':
            return 'solid';
        case 'waiting_on_customer':
            return 'outline';
        case 'resolved':
            return 'success';
        default:
            return 'neutral';
    }
}
