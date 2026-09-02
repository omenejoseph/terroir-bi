import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Compose Tailwind class lists, letting later classes win over earlier ones in
 * the same utility group. Without twMerge, a `class="p-2"` prop passed to a
 * component whose base is `p-4` would produce both and lose to source order.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
