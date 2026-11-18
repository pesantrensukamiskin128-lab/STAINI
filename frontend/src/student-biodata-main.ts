import './assets/main.css'
import 'vue-toastification/dist/index.css'

import { createApp } from 'vue'
import Toast, { POSITION, type PluginOptions } from 'vue-toastification'
import StudentSelfProfilePortal from './views/StudentSelfProfilePortal.vue'

const toastOptions: PluginOptions = {
  position: POSITION.TOP_RIGHT,
  timeout: 3000,
  closeOnClick: true,
  pauseOnHover: true,
}

createApp(StudentSelfProfilePortal)
  .use(Toast, toastOptions)
  .mount('#app')
