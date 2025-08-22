import { defineConfig } from 'vite'

export default defineConfig({
  root: '.',
  build: {
    outDir: 'public/assets',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        admin: 'resources/admin.js'
      },
      output: {
        entryFileNames: `[name].js`,
        assetFileNames: `[name][extname]`
      }
    }
  }
})

