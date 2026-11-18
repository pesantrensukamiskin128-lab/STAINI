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
    outDir: 'dist-academic-tools',
    emptyOutDir: true,
    lib: {
      entry: fileURLToPath(new URL('./src/academic-tools-main.ts', import.meta.url)),
      formats: ['es'],
      fileName: () => 'academic-tools-20261005-1640.js',
      cssFileName: 'academic-tools-20261005-1640',
    },
    cssCodeSplit: false,
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
        assetFileNames: 'academic-tools-20261005-1640.[ext]',
      },
    },
  },
})
