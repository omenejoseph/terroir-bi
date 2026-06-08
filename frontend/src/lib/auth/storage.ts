import { STORAGE_KEYS } from "@/lib/config";

/** SSR-safe token persistence. The token is the whole session (it's tenant-bound). */
export const tokenStorage = {
  get(): string | null {
    if (typeof window === "undefined") return null;
    return window.localStorage.getItem(STORAGE_KEYS.token);
  },
  set(token: string) {
    if (typeof window === "undefined") return;
    window.localStorage.setItem(STORAGE_KEYS.token, token);
  },
  clear() {
    if (typeof window === "undefined") return;
    window.localStorage.removeItem(STORAGE_KEYS.token);
  },
};