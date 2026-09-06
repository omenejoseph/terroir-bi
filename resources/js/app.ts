import '../css/app.css';

import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

import ConfirmDialogHost from './components/ConfirmDialogHost.vue';

const appName = import.meta.env.VITE_APP_NAME ?? 'Terroir';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    /**
     * Pages are code-split per route: `resolvePageComponent` turns the glob into
     * a dynamic import, so a visit only downloads the page it lands on.
     */
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        // ConfirmDialogHost is a sibling root, not a child of App: one dialog,
        // shared by every page/component via useConfirm's module-level state,
        // rather than each screen mounting (and re-mounting on navigation) its
        // own copy.
        createApp({ render: () => [h(App, props), h(ConfirmDialogHost)] })
            .use(plugin)
            .mount(el);
    },

    progress: {
        // Matches --color-primary; the bar is the only chrome shown during a visit.
        color: '#3a3a3a',
        showSpinner: false,
    },
});
