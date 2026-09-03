/**
 * The header's notification feed. Mirrors
 * App\DataTransferObjects\NotificationData and App\Enums\NotificationType.
 */

export type NotificationType =
    | 'MENTION'
    | 'NEW_ORDER'
    | 'ORDER_STATUS'
    | 'REPLY'
    | 'ANNOUNCEMENT'
    | 'AI_IMPORT_READY'
    | 'AI_IMPORT_FAILED';

export interface NotificationItem {
    id: string;
    type: NotificationType;
    title: string;
    body: string | null;
    data: Record<string, string>;
    is_read: boolean;
    created_at: string | null;
}
