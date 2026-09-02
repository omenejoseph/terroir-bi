<script setup lang="ts">
import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import type { SharedProps } from '@/types';

/**
 * Switches the active tenant. The membership list is a lazy shared prop, so it
 * is only serialised when this component asks for it (on first open) rather than
 * on every page visit.
 */
const page = usePage<SharedProps>();

const open = ref(false);
const switching = ref(false);

watch(open, (isOpen) => {
    if (isOpen && page.props.tenants.length === 0) {
        router.reload({ only: ['tenants'] });
    }
});

function select(tenantId: string): void {
    if (tenantId === page.props.tenant?.id) {
        open.value = false;

        return;
    }

    switching.value = true;

    router.post(
        '/tenant/switch',
        { tenant_id: tenantId },
        {
            onFinish: () => {
                switching.value = false;
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <div v-if="page.props.tenant" class="relative">
        <button
            type="button"
            class="rounded-lg border border-input px-3 py-1.5 text-sm font-medium hover:bg-accent disabled:opacity-50"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :disabled="switching"
            @click="open = !open"
        >
            {{ page.props.tenant.name }}
        </button>

        <ul
            v-if="open"
            class="absolute right-0 z-30 mt-2 min-w-52 overflow-hidden rounded-lg border border-border bg-popover py-1 shadow-lg"
            role="listbox"
        >
            <li v-for="membership in page.props.tenants" :key="membership.tenant_id">
                <button
                    type="button"
                    role="option"
                    :aria-selected="membership.tenant_id === page.props.tenant.id"
                    class="block w-full px-3 py-2 text-left text-sm hover:bg-accent"
                    @click="select(membership.tenant_id)"
                >
                    {{ membership.name }}
                </button>
            </li>
            <li v-if="page.props.tenants.length === 0" class="px-3 py-2 text-sm text-muted-foreground">Loading…</li>
        </ul>
    </div>
</template>
