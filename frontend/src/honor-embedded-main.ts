import { createApp } from 'vue'
import Toast, { POSITION, type PluginOptions } from 'vue-toastification'
import 'vue-toastification/dist/index.css'
import './assets/main.css?inline'
import HonorEmbeddedApp from './views/keuangan/HonorEmbeddedApp.vue'

const toastOptions: PluginOptions = {
  position: POSITION.TOP_RIGHT,
  timeout: 3000,
  closeOnClick: true,
  pauseOnHover: true,
}

export function mount(element: Element, honorPage: 'manage' | 'mine' | 'review') {
  const app = createApp(HonorEmbeddedApp, { honorPage })
  app.use(Toast, toastOptions)
  app.mount(element)
  return () => app.unmount()
}
