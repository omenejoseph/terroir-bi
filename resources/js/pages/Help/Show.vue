<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, LifeBuoy } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import { useTranslations } from '@/composables/useTranslations';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * One help article (Web\HelpController::show). The body is HTML the server
 * rendered from our own Markdown with raw HTML stripped (HelpLibrary), so it
 * is safe to inject. Links into the app ("/customers/pricing-tiers") are
 * followed as Inertia visits so the shell doesn't reload.
 */
const props = defineProps<{
    article: { id: string; title: string; summary: string; topic: string; html: string };
    related: { id: string; title: string; summary: string; url: string }[];
}>();

const { t } = useTranslations();

const supportHref = computed(() => `/support?new=1&from=${encodeURIComponent(`/help/${props.article.id}`)}`);

function followInternalLinks(event: MouseEvent): void {
    const anchor = (event.target as HTMLElement).closest('a');
    const href = anchor?.getAttribute('href');

    if (href && href.startsWith('/') && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
        event.preventDefault();
        router.visit(href);
    }
}
</script>

<template>
    <Head :title="article.title" />
    <div class="max-w-3xl space-y-6">
        <Button variant="ghost" size="sm" href="/help">
            <ArrowLeft class="size-3.5" :stroke-width="1.5" />
            {{ t('All help articles') }}
        </Button>

        <header class="space-y-1">
            <p class="text-xs text-muted-foreground">{{ article.topic }}</p>
            <h1 class="text-2xl font-semibold text-foreground">{{ article.title }}</h1>
            <p class="text-sm text-muted-foreground">{{ article.summary }}</p>
        </header>

        <!-- eslint-disable-next-line vue/no-v-html -->
        <article class="help-article" @click="followInternalLinks" v-html="article.html" />

        <section v-if="related.length" class="space-y-2">
            <h2 class="text-sm font-semibold text-foreground">{{ t('Related') }}</h2>
            <ul class="space-y-2">
                <li v-for="item in related" :key="item.id">
                    <Link :href="item.url" class="text-sm text-foreground hover:underline">{{ item.title }}</Link>
                    <p class="text-xs text-muted-foreground">{{ item.summary }}</p>
                </li>
            </ul>
        </section>

        <Card class="flex flex-wrap items-center justify-between gap-3 p-5">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <LifeBuoy class="mt-0.5 size-5 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                <div>
                    <p class="text-sm font-medium text-foreground">{{ t('Still stuck?') }}</p>
                    <p class="text-xs text-muted-foreground">{{ t('Our support team answers inside the app and by email.') }}</p>
                </div>
            </div>
            <Button size="sm" variant="outline" :href="supportHref">{{ t('Contact support') }}</Button>
        </Card>
    </div>
</template>

<style scoped>
.help-article {
    color: var(--color-foreground);
    font-size: 0.9375rem;
    line-height: 1.65;
}
.help-article :deep(h2) {
    margin: 1.75rem 0 0.5rem;
    font-size: 1.0625rem;
    font-weight: 600;
}
.help-article :deep(h3) {
    margin: 1.25rem 0 0.375rem;
    font-size: 0.9375rem;
    font-weight: 600;
}
.help-article :deep(p) {
    margin: 0.625rem 0;
}
.help-article :deep(ul),
.help-article :deep(ol) {
    margin: 0.625rem 0;
    padding-left: 1.375rem;
}
.help-article :deep(ul) {
    list-style: disc;
}
.help-article :deep(ol) {
    list-style: decimal;
}
.help-article :deep(li) {
    margin: 0.25rem 0;
}
.help-article :deep(strong) {
    font-weight: 600;
}
.help-article :deep(a) {
    text-decoration: underline;
    text-underline-offset: 2px;
}
.help-article :deep(blockquote) {
    margin: 1rem 0;
    border-left: 3px solid var(--color-border);
    padding: 0.25rem 0 0.25rem 1rem;
    color: var(--color-muted-foreground);
}
.help-article :deep(code) {
    border-radius: 0.25rem;
    background: var(--color-muted);
    padding: 0.0625rem 0.3125rem;
    font-size: 0.8125rem;
}
</style>
