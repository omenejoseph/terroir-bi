<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import LanguageSwitcher from '@/components/LanguageSwitcher.vue';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen flex-col items-center justify-center gap-6 px-4 py-12">
        <div class="w-full max-w-sm">
            <slot />
        </div>

        <LanguageSwitcher />
    </div>
</template>
