import { defineConfig } from 'vite'
import path from 'path'

// Minimal Vite config to emit specific JS entries into public/assets
export default defineConfig({
  build: {
    outDir: 'public/assets',
    emptyOutDir: false, // don't wipe existing assets already in public/assets
    manifest: false,
    rollupOptions: {
      input: {
        'js/hero': path.resolve(__dirname, 'resources/js/hero.js'),
      },
      output: {
        // keep folder/name stable (no hash) to match Twig includes
        entryFileNames: (chunk) => {
          if (chunk.name === 'js/hero') return 'js/hero.js'
          return '[name].js'
        },
        assetFileNames: '[name][extname]',
      },
    },
  },
})

