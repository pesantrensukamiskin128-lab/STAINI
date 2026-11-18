import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  base: '/assets/',
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: 'dist-honor-embedded',
    emptyOutDir: true,
    lib: {
      entry: fileURLToPath(new URL('./src/honor-embedded-main.ts', import.meta.url)),
      formats: ['es'],
      fileName: () => 'honor-embedded.js',
      cssFileName: 'honor-embedded',
    },
    cssCodeSplit: false,
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
        assetFileNames: 'honor-embedded.[ext]',
      },
    },
  },
})
