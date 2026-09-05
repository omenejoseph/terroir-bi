<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown } from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import NavRow from '@/components/NavRow.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Separator from '@/components/ui/Separator.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_NAV_CATEGORIES } from '@/lib/adminNavigation';

/**
 * The platform-admin sidebar — same chrome/geometry as AppSidebar.vue (240px
 * rail, 56px header, same type ramp and tokens) so `/admin` reads as the same
 * product, but with a static nav (ADMIN_NAV_CATEGORIES) instead of the
 * capability/module-filtered tenant one, and no tenant-context footer
 * (no role badge, no tenant switcher — a platform admin need not belong to
 * any tenant).
 */
const { user } = useAuth();
const { t } = useTranslations();

const overview = computed(() => ADMIN_NAV_CATEGORIES.filter((c) => c.label === 'Overview'));
const categories = computed(() => ADMIN_NAV_CATEGORIES.filter((c) => c.label !== 'Overview'));

/** Categories collapse; all start open, matching the tenant sidebar's default. */
const collapsed = ref<Record<string, boolean>>({});

function toggle(label: string): void {
    collapsed.value[label] = !collapsed.value[label];
}
</script>

<template>
    <div class="flex h-full w-60 flex-col border-r border-sidebar-border bg-sidebar">
        <!-- Header: 56px, logo tile + wordmark, "Admin" instead of the product tagline -->
        <div class="flex h-14 shrink-0 items-center gap-2.5 px-3">
            <AppLogo />
            <div class="min-w-0 flex-1">
                <p class="truncate text-13 leading-[16.25px] font-semibold text-foreground">Terroir</p>
                <p class="truncate text-2xs text-muted-foreground">{{ t('Admin') }}</p>
            </div>
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto p-2" :aria-label="t('Admin navigation')">
            <section v-for="category in overview" :key="category.label" class="pb-3">
                <ul class="space-y-px">
                    <li v-for="item in category.items" :key="item.label">
                        <NavRow :item="item" />
                    </li>
                </ul>
            </section>

            <div v-if="overview.length" class="pb-2"><Separator class="bg-sidebar-border" /></div>

            <section v-for="category in categories" :key="category.label" class="pb-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-nav px-1.5 py-1 text-13 text-muted-foreground"
                    :aria-expanded="!collapsed[category.label]"
                    @click="toggle(category.label)"
                >
                    <ChevronDown
                        class="size-3 shrink-0 transition-transform"
                        :class="collapsed[category.label] && '-rotate-90'"
                    />
                    <span>{{ t(category.label) }}</span>
                </button>

                <ul v-show="!collapsed[category.label]" class="space-y-px pt-0.5">
                    <li v-for="item in category.items" :key="item.label">
                        <NavRow :item="item" />
                    </li>
                </ul>
            </section>
        </nav>

        <!-- Footer: identity + escape hatches back to the tenant app / sign out -->
        <div class="shrink-0 space-y-1 border-t border-sidebar-border p-3">
            <div class="flex items-center gap-2.5 rounded-nav p-2">
                <Avatar :name="user?.name" tone="primary" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-13 leading-[16.25px] font-semibold text-foreground">
                        {{ user?.name }}
                    </span>
                    <span class="block truncate text-2xs text-muted-foreground">{{ user?.email }}</span>
                </span>
            </div>
            <Link
                href="/dashboard"
                class="flex items-center gap-2.5 rounded-nav px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-sidebar-active hover:text-foreground"
            >
                <ArrowLeft class="size-[15px] shrink-0" :stroke-width="1.5" />
                <span>{{ t('Back to app') }}</span>
            </Link>
            <Link
                href="/logout"
                method="post"
                as="button"
                class="flex w-full items-center gap-2.5 rounded-nav px-2 py-1.5 text-left text-sm text-muted-foreground transition-colors hover:bg-sidebar-active hover:text-foreground"
            >
                {{ t('Log out') }}
            </Link>
        </div>
    </div>
</template>
