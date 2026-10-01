import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import '../css/app.css';
import { initPwa } from './lib/pwa';
initPwa();

const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
createInertiaApp({
  title: (title) => title ? `${title} · Personal OS` : 'Personal OS',
  resolve: (name) => (pages[`./Pages/${name}.vue`] as any).default,
  setup({ el, App, props, plugin }) { createApp({ render: () => h(App, props) }).use(plugin).mount(el); },
  progress: { color: '#c4624e', showSpinner: false },
});
import '../css/glass.css';
import '../css/playful.css';
