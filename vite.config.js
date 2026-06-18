import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            // The build-time bunny('Instrument Sans') font fetch was removed so the
            // asset build is hermetic (no external network call at build time, which
            // fails behind restricted network policies). The UI falls back to the
            // default system font stack; re-add the `fonts:` option to restore it.
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
