import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { fluent } from './i18n';

// The student player and the teacher's gradebook view (gbviewassess) are two apps that share
// one built stylesheet, so player-theme.css scopes every rule under this class. It is set
// here, in the student entry only, rather than in the PHP page, so nothing outside this
// bundle has to know about it -- and gbviewassess, which is not in scope for the restyle,
// keeps the look it has.
document.body.classList.add('nx-player');

createApp(App)
  .use(router)
  .use(fluent)
  .mount('#app');
