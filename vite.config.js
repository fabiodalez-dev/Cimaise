import { defineConfig } from 'vite'

export default defineConfig({
  root: '.',
  build: {
    outDir: 'public/assets',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        admin: 'resources/admin.js',
        app: 'resources/app.css'
      },
      output: {
        entryFileNames: `[name].js`,
        chunkFileNames: `[name].js`,
        assetFileNames: `[name][extname]`
      }
    }
  }
})

