import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, Fragment, h } from 'vue';
import CookieConsent from './Components/CookieConsent.vue';
import { initializeAnalyticsConsent, trackPageView } from './Support/analyticsConsent';

initializeAnalyticsConsent();
router.on('finish', trackPageView);

createInertiaApp({
    title: (title) => (title ? `${title} - Tilbudsfinder` : 'Tilbudsfinder'),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => h(Fragment, null, [h(App, props), h(CookieConsent)]),
        })
            .use(plugin)
            .mount(el);
    },
});
