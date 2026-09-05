<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import { useTranslations } from '@/composables/useTranslations';
import type { SharedProps } from '@/types';

/**
 * A persistent, impossible-to-miss banner while a platform admin is "logged
 * in as" another user — see App\Actions\Auth\StartImpersonationAction. The
 * middleware chain (not this banner) is what actually restricts an
 * impersonated session; this only makes the state visible and gives a way out.
 */
const page = usePage<SharedProps>();
const { t } = useTranslations();

const impersonating = computed(() => page.props.impersonating);

function stop(): void {
    router.post('/impersonation/stop');
}
</script>

<template>
    <div
        v-if="impersonating"
        class="flex items-center justify-center gap-3 bg-destructive px-4 py-2 text-center text-xs font-medium text-destructive-foreground"
    >
        <span>
            {{
                t(':admin is viewing as :user', {
                    admin: impersonating.impersonator_name,
                    user: impersonating.target_name,
                })
            }}
        </span>
        <button
            type="button"
            class="underline underline-offset-2 hover:no-underline"
            @click="stop"
        >
            {{ t('Stop impersonating') }}
        </button>
    </div>
</template>
