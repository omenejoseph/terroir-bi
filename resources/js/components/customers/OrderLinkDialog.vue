<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, Copy, Link2, RefreshCw } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';

/**
 * "Generate Order Link" (Figma 231:9336's tab-row button) — a self-service
 * ordering token a customer can use without an account. Mirrors the outgoing
 * React app's OrderLinkSection and the API's customers.tokens endpoints
 * (Api\CustomerController::showToken/generateToken/revokeToken), just as an
 * Inertia dialog instead of an always-visible card.
 *
 * `token` is `undefined` until fetched (an Inertia::optional prop, only
 * requested once this dialog opens), `null` once fetched but none exists yet.
 *
 * The link's destination — a public catalog + order form at `/order/:token`
 * — is not built in this app yet (it exists only in the outgoing React
 * frontend, at frontend/src/app/order/[token]). Generating and sharing a
 * link is real and works today; what a customer's browser finds there is a
 * separate, unbuilt feature.
 */
const props = defineProps<{ open: boolean; customerId: string; token: string | null | undefined }>();

const emit = defineEmits<{ close: [] }>();

const generating = ref(false);
const revoking = ref(false);
const copied = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open && props.token === undefined) {
            router.reload({ only: ['orderToken'] });
        }
    },
    { immediate: true },
);

const url = computed(() => (props.token ? `${window.location.origin}/order/${props.token}` : null));

/*
  `only: ['orderToken']` matters here, not just as an optimisation: the Show
  page carries several other Inertia::optional props (pricing, orderHistory,
  consignment) that may already be loaded from an earlier tab visit. A plain
  post/delete with no `only` is a full (non-partial) visit, and a full visit
  never resolves Optional props at all — it would silently reset all of them
  to undefined, resetting the Pricing/Order History tab counts to 0 along
  with generating the link.
*/
function generate(): void {
    generating.value = true;
    router.post(
        `/customers/${props.customerId}/order-token`,
        {},
        {
            preserveScroll: true,
            only: ['orderToken'],
            onFinish: () => (generating.value = false),
        },
    );
}

function revoke(): void {
    if (!confirm('Revoke this order link? The customer will no longer be able to use it.')) return;

    revoking.value = true;
    router.delete(`/customers/${props.customerId}/order-token`, {
        preserveScroll: true,
        only: ['orderToken'],
        onFinish: () => (revoking.value = false),
    });
}

async function copy(): Promise<void> {
    if (!url.value) return;

    await navigator.clipboard.writeText(url.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <Dialog :open="open" title="Order link" @close="emit('close')">
        <div class="space-y-4">
            <p class="text-xs text-muted-foreground">
                A link this customer can use to place orders themselves, without an account.
            </p>

            <p v-if="token === undefined" class="text-xs text-muted-foreground">Loading…</p>

            <template v-else-if="token === null">
                <Button size="sm" :disabled="generating" @click="generate">
                    <Link2 class="size-3.5" :stroke-width="1.5" />
                    {{ generating ? 'Generating…' : 'Generate link' }}
                </Button>
            </template>

            <template v-else>
                <div class="flex items-center gap-2">
                    <input
                        readonly
                        :value="url"
                        aria-label="Order link"
                        class="h-8 min-w-0 flex-1 border border-input bg-muted/40 px-2.5 font-mono text-xs"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button variant="outline" size="sm" @click="copy">
                        <component :is="copied ? Check : Copy" class="size-3.5" :stroke-width="1.5" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>

                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="generating" @click="generate">
                        <RefreshCw class="size-3.5" :stroke-width="1.5" />
                        Regenerate
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="revoking"
                        class="border-destructive/40 text-destructive hover:bg-destructive/10"
                        @click="revoke"
                    >
                        Revoke
                    </Button>
                </div>
            </template>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Close</Button>
        </template>
    </Dialog>
</template>
