import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: '/assets/',
  plugins: [vue()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: 'dist-class-schedule',
    emptyOutDir: true,
    lib: {
      entry: fileURLToPath(new URL('./src/class-schedule-main.ts', import.meta.url)),
      formats: ['es'],
      fileName: () => 'class-schedule-20261005-2114.js',
      cssFileName: 'class-schedule-20261005-2114',
    },
    cssCodeSplit: false,
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
        assetFileNames: 'class-schedule-20261005-2114.[ext]',
      },
    },
  },
})
