import '../css/app.css';
import './bootstrap';

import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h, watch } from 'vue';
import { createI18n } from 'vue-i18n';
import { messages, supportedLocale } from './localization/messages';
import { applyDocumentLocale } from './localization/documentLocale';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { installClientVisibility } from './clientVisibility';

const appName = import.meta.env.VITE_APP_NAME || 'Builderad';
const i18n = createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages });
const visibility = installClientVisibility();

function syncLocale(value: unknown) {
    const locale = supportedLocale(value);
    i18n.global.locale.value = locale;
    applyDocumentLocale(locale, document.documentElement);
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name, page) => {
        if (!page) throw new Error('Page authority evidence is required.');
        await visibility.authorize(page);
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        );
    },
    setup({ el, App, props, plugin }) {
        syncLocale(props.initialPage.props.locale);
        createApp({
            setup() {
                const page = usePage();
                watch(() => page.props?.locale, syncLocale, { flush: 'sync' });
                return () => h(App, props);
            },
        })
            .use(plugin)
            .use(i18n)
            .use(ZiggyVue)
            .mount(el);
        visibility.mounted(props.initialPage);
    },
    progress: {
        color: '#4B5563',
    },
});
