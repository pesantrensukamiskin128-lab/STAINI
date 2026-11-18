import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    VitePWA({
      filename: 'sw-asc-v3.js',
      registerType: 'prompt',
      includeAssets: ['favicon.ico', 'icons/*.png', 'icons/*.svg'],
      manifest: {
        name: 'NURIS - Nurul Iman Academic Integrated System',
        short_name: 'NURIS',
        description: 'NURIS - Nurul Iman Academic Integrated System',
        theme_color: '#059669',
        background_color: '#ffffff',
        display: 'standalone',
        orientation: 'portrait',
        scope: '/',
        start_url: '/',
        lang: 'id',
        icons: [
          {
            src: 'icons/pwa-192x192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'icons/pwa-192x192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'maskable',
          },
          {
            src: 'icons/pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'icons/pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        skipWaiting: true,
        clientsClaim: true,
        // Cache strategi untuk assets statis
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        // Exclude file asli yang besar
        globIgnores: ['Icon ASC.png', 'icons/pwa-icon.svg'],
        // Import custom push notification handler
        importScripts: ['sw-push.js'],
        // Jangan cache API calls — data akademik harus selalu fresh
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [
          /^\/api\//,
          /^\/perpustakaan(?:\/|$)/,
          /^\/biodata(?:\/|$)/,
        ],
        runtimeCaching: [
          {
            // Cache API institution/public (logo & nama institusi)
            urlPattern: /\/api\/institution\/public/,
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'institution-cache',
              expiration: { maxAgeSeconds: 60 * 60 * 24 }, // 1 hari
            },
          },
        ],
      },
      devOptions: {
        enabled: false, // nonaktifkan di dev mode agar tidak ganggu HMR
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    rollupOptions: {
      input: {
        main: fileURLToPath(new URL('./index.html', import.meta.url)),
        digilib: fileURLToPath(new URL('./perpustakaan/index.html', import.meta.url)),
      },
    },
  },
  server: {
    port: 3000,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  envPrefix: 'VITE_',
})
