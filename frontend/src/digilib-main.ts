import './assets/main.css'

import { createApp, h } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHashHistory, RouterView } from 'vue-router'
import Toast, { POSITION, type PluginOptions } from 'vue-toastification'
import 'vue-toastification/dist/index.css'

import { useAuthStore } from '@/stores/auth'
import LibraryCatalogView from '@/views/digilib/LibraryCatalogView.vue'
import DigitalLibraryMemberView from '@/views/digilib/DigitalLibraryMemberView.vue'
import DigitalLibraryAdminView from '@/views/digilib/DigitalLibraryAdminView.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', redirect: '/perpustakaan' },
    { path: '/perpustakaan', component: LibraryCatalogView },
    { path: '/perpustakaan/akun', component: DigitalLibraryMemberView, meta: { permission: 'digilib.borrow' } },
    { path: '/digilib/kelola', component: DigitalLibraryAdminView, meta: { permission: 'digilib.books.manage' } },
    { path: '/:pathMatch(.*)*', redirect: '/perpustakaan' },
  ],
})

router.beforeEach(async (to) => {
  if (to.path === '/perpustakaan/repository') {
    window.location.assign('/repository')
    return false
  }

  if (to.path === '/login' || to.path === '/dashboard') {
    window.location.assign(to.path)
    return false
  }

  if (!to.meta.permission) return true

  const auth = useAuthStore()
  if (!auth.token) {
    window.location.assign('/login')
    return false
  }
  if (!auth.user) await auth.fetchMe()
  if (auth.hasPermission(String(to.meta.permission))) return true

  window.location.assign('/forbidden')
  return false
})

const toastOptions: PluginOptions = {
  position: POSITION.TOP_RIGHT,
  timeout: 3000,
  closeOnClick: true,
  pauseOnHover: true,
}

const app = createApp({ render: () => h(RouterView) })
app.use(createPinia())
app.use(router)
app.use(Toast, toastOptions)
app.mount('#app')

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw-asc-v3.js', { scope: '/' }).catch((error) => {
    console.error('ASC service worker registration failed:', error)
  })
}
