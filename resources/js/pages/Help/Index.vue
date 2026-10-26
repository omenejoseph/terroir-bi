<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, LifeBuoy, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * The help center home (Web\HelpController::index, docs/22-help-center-plan.md):
 * a search box over every article this member can see, the articles grouped
 * by topic, and a way through to support when the answer isn't here.
 */
interface ArticleSummary {
    id: string;
    title: string;
    summary: string;
    url: string;
}

interface SearchHit extends ArticleSummary {
    snippet: string;
}

defineProps<{
    topics: { key: string; label: string; articles: ArticleSummary[] }[];
}>();

const { t } = useTranslations();

const query = ref(new URLSearchParams(window.location.search).get('q') ?? '');
const results = ref<SearchHit[] | null>(null);
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function search(term: string): Promise<void> {
    controller?.abort();

    if (term.trim().length < 2) {
        results.value = null;
        loading.value = false;

        return;
    }

    controller = new AbortController();
    loading.value = true;

    try {
        const response = await fetch(`/help/search?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        const body = response.ok ? ((await response.json()) as { results: SearchHit[] }) : { results: [] };
        results.value = body.results;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') results.value = [];
    } finally {
        loading.value = false;
    }
}

watch(
    query,
    (term) => {
        clearTimeout(timer);
        timer = setTimeout(() => search(term), 200);
    },
    { immediate: true },
);
</script>

<template>
    <Head :title="t('Help')" />
    <div class="max-w-4xl space-y-6">
        <PageHeader :title="t('Help')" :description="t('Short guides to everything in Terroir. Search, or browse by topic.')">
            <template #actions>
                <Button size="sm" variant="outline" href="/support">{{ t('Your support requests') }}</Button>
            </template>
        </PageHeader>

        <div class="relative">
            <Search class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" :stroke-width="1.5" />
            <input
                v-model="query"
                type="search"
                class="h-11 w-full rounded-lg border border-input bg-card pr-4 pl-10 text-sm shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :placeholder="t('How do I… (e.g. add a customer, pricing tiers, order link)')"
                :aria-label="t('Search help')"
                autofocus
            />
        </div>

        <!-- Search results replace the topic list while there is a query. -->
        <section v-if="results !== null" class="space-y-2" aria-live="polite">
            <p class="text-xs text-muted-foreground">
                {{ loading ? t('Searching…') : t(':count results', { count: results.length }) }}
            </p>
            <ul v-if="results.length" class="overflow-hidden rounded-lg border border-border bg-card">
                <li v-for="hit in results" :key="hit.id" class="border-b border-border last:border-b-0">
                    <Link :href="hit.url" class="block px-4 py-3 hover:bg-muted/40">
                        <p class="text-sm font-medium text-foreground">{{ hit.title }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ hit.snippet }}</p>
                    </Link>
                </li>
            </ul>
            <Card v-else-if="!loading" class="p-5 text-sm text-muted-foreground">
                {{ t('Nothing matches that yet. Try other words — or ask us directly.') }}
                <Link :href="`/support?new=1&from=${encodeURIComponent('/help?q=' + query)}`" class="ml-1 text-foreground underline underline-offset-2">
                    {{ t('Contact support') }}
                </Link>
            </Card>
        </section>

        <div v-else class="grid gap-4 sm:grid-cols-2">
            <Card v-for="topic in topics" :key="topic.key" class="p-5">
                <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
                    <BookOpen class="size-4 text-muted-foreground" :stroke-width="1.5" />
                    {{ topic.label }}
                </h3>
                <ul class="space-y-2">
                    <li v-for="article in topic.articles" :key="article.id">
                        <Link :href="article.url" class="text-sm text-foreground hover:underline">{{ article.title }}</Link>
                        <p class="text-xs text-muted-foreground">{{ article.summary }}</p>
                    </li>
                </ul>
            </Card>
        </div>

        <Card class="flex flex-wrap items-center justify-between gap-3 p-5">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <LifeBuoy class="mt-0.5 size-5 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                <div>
                    <p class="text-sm font-medium text-foreground">{{ t('Still stuck?') }}</p>
                    <p class="text-xs text-muted-foreground">{{ t('Our support team answers inside the app and by email.') }}</p>
                </div>
            </div>
            <Button size="sm" variant="outline" href="/support?new=1&from=%2Fhelp">{{ t('Contact support') }}</Button>
        </Card>
    </div>
</template>
