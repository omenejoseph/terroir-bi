import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            // The build-time bunny('Instrument Sans') font fetch was removed so the
            // asset build is hermetic (no external network call at build time, which
            // fails behind restricted network policies). The UI falls back to the
            // default system font stack; re-add the `fonts:` option to restore it.
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Let Vite (not Vue's compiler) resolve asset URLs, so `@vite`
                    // manifest hashing applies to images referenced in templates.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
