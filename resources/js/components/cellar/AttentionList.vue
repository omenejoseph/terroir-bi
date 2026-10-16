<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';
import type { CellarAttentionItem } from '@/types/cellar';

/**
 * The cellar's "needs attention" list (CellarAttentionQuery): one row per
 * problem — what's wrong, with which wine, and the one action that deals with
 * it (plan a reading, plan SO₂, a warm/cool task, open the lot or Today).
 * "Hide until tomorrow" is a per-browser convenience; the list itself always
 * comes from the server.
 */
const props = withDefaults(defineProps<{ items: CellarAttentionItem[]; showLot?: boolean }>(), { showLot: true });

const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));

const KEY = 'terroir.cellar.attentionHidden';
const today = new Date().toISOString().slice(0, 10);
const hidden = ref<Record<string, string>>({});
onMounted(() => {
    try {
        const saved = JSON.parse(window.localStorage.getItem(KEY) ?? '{}') as Record<string, string>;
        hidden.value = Object.fromEntries(Object.entries(saved).filter(([, day]) => day === today));
    } catch {
        hidden.value = {};
    }
});
function hideAll(): void {
    hidden.value = { ...hidden.value, ...Object.fromEntries(props.items.map((i) => [i.key, today])) };
    try {
        window.localStorage.setItem(KEY, JSON.stringify(hidden.value));
    } catch {
        // storage unavailable — hidden for this visit only
    }
}

const visible = computed(() => props.items.filter((i) => hidden.value[i.key] === undefined && (i.action.post === null || canManage.value || i.action.href !== null)));
const SQUARE: Record<string, string> = { critical: 'bg-destructive', high: 'bg-tag-orange-foreground', medium: 'bg-tag-orange-foreground/50' };

const busy = ref<string | null>(null);
function act(item: CellarAttentionItem): void {
    if (!item.action.post) return;
    busy.value = item.key;
    router.post(item.action.post.url, item.action.post.data as never, { preserveScroll: true, onFinish: () => (busy.value = null) });
}
</script>

<template>
    <section v-if="visible.length" :aria-label="t('Needs attention')" class="border border-tag-orange-foreground/30 bg-tag-orange/20">
        <div class="flex items-center justify-between gap-2 border-b border-tag-orange-foreground/20 px-4 py-2.5">
            <span class="text-sm font-semibold text-tag-orange-foreground">{{ t('Needs attention · :n', { n: visible.length }) }}</span>
            <button type="button" class="text-xs text-muted-foreground hover:text-foreground" @click="hideAll">{{ t('Hide until tomorrow') }}</button>
        </div>
        <ul>
            <li v-for="item in visible" :key="item.key" class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-tag-orange-foreground/10 px-4 py-2.5 text-sm last:border-0">
                <span :class="cn('size-2.5 shrink-0', SQUARE[item.severity])" :title="t(item.severity)" />
                <Link v-if="showLot" :href="`/cellar/lots/${item.lot_id}`" class="min-w-0 flex-1 truncate font-semibold hover:underline sm:w-44 sm:flex-none">{{ item.lot_name }}</Link>
                <!-- On a phone the message takes its own line under the lot; on wider screens it sits beside it. -->
                <span :class="cn('min-w-0', showLot ? 'basis-full pl-5.5 sm:basis-0 sm:flex-1 sm:pl-0' : 'flex-1')">
                    {{ item.message }}
                    <span v-if="item.suggestion" class="text-muted-foreground">{{ item.suggestion }}</span>
                </span>
                <Button v-if="item.action.href" size="sm" variant="outline" :href="item.action.href" class="ml-5.5 sm:ml-0">{{ item.action.label }}</Button>
                <Button v-else-if="item.action.post && canManage" size="sm" class="ml-5.5 sm:ml-0" :disabled="busy === item.key" @click="act(item)">{{ item.action.label }}</Button>
            </li>
        </ul>
    </section>
</template>
