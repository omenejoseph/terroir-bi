import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

/**
 * Merge conditional class names and resolve Tailwind conflicts (last wins).
 * Every UI primitive uses this so callers can override styling inline via a
 * `className` prop without fighting specificity — keeping components decoupled
 * from where they're used.
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}