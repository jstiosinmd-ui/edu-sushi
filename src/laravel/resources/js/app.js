import '../css/sushi.css';
import '../css/studio.css';
import '../css/learning.css';
import '../css/focused.css';
import '../css/refresh.css';
import '../css/motion.css';
import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import Shell from './Components/Shell.vue';
createInertiaApp({
    title: title => title ? title + ' · SUSHI' : 'SUSHI Clerks Comprehensive Exam Reviewer',
    resolve: async name => {
        const pages = import.meta.glob('./Pages/*.vue');
        const page = await pages['./Pages/' + name + '.vue']();
        if (!['Login','Password','Invitation'].includes(name)) page.default.layout = Shell;
        return page;
    },
    setup({ el, App, props, plugin }) { createApp({ render: () => h(App, props) }).use(plugin).mount(el); },
    progress: { color:'#9D3656' },
});
