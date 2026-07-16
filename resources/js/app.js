import '../css/app.css';

// Plan 06 Phase 4b design §6.6: no eager `window.Echo = new Echo(...)`
// here. Echo construction is lazy — @sendtrap/core/Composables/useEcho.js,
// invoked from MessageReader's own onMounted — so a Community install with
// no Reverb config degrades to "no live updates" instead of failing to
// construct Echo on every page load.

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Sendtrap Community';

// Plan 06 Phase 4b design §7.4: two page globs merged — the host's own
// Pages plus the package's (currently Share/Show.vue + Share/InboxShow.vue).
// resolvePageComponent() only ever sees `./Pages/${name}.vue` keys, so the
// package glob's disk-relative keys are remapped onto that same shape
// before merging. Host pages win on a name collision.
const hostPages = import.meta.glob('./Pages/**/*.vue');
const corePages = import.meta.glob('../../vendor/sendtrap/core/resources/js/Pages/**/*.vue');
const pages = { ...hostPages };
for (const [path, loader] of Object.entries(corePages)) {
    const key = path.replace('../../vendor/sendtrap/core/resources/js/Pages/', './Pages/');
    if (!(key in pages)) pages[key] = loader;
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, pages),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#2563eb',
    },
});
