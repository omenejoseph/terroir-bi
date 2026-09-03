import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import type { SharedProps } from '@/types';

const WILDCARD = '*';

/**
 * Access to the shared auth props, plus the `can()` check used to show or hide
 * UI affordances.
 *
 * This is presentation only. Every route is still gated server-side with
 * `can:*` middleware, so hiding a button is defence in depth — never the
 * security boundary. Unlike the outgoing React app, the role → capability map
 * is not duplicated here: the server sends the already-resolved list.
 */
export function useAuth() {
    const page = usePage<SharedProps>();

    const user = computed(() => page.props.auth.user);
    const roles = computed(() => page.props.auth.roles);
    const capabilities = computed(() => page.props.auth.capabilities);
    const tenant = computed(() => page.props.tenant);
    const modules = computed(() => page.props.modules);

    function can(capability: string): boolean {
        const granted = page.props.auth.capabilities;

        return granted.includes(WILDCARD) || granted.includes(capability);
    }

    /** True when the member holds at least one of the given capabilities. */
    function canAny(...wanted: string[]): boolean {
        return wanted.some(can);
    }

    /**
     * True when the active tenant's plan includes the given module. Like
     * `can()`, this is presentation only — `EnforceModuleAccess` is the
     * actual security boundary.
     */
    function hasModule(module: string): boolean {
        return page.props.modules.includes(module);
    }

    return { user, roles, capabilities, tenant, modules, can, canAny, hasModule };
}
