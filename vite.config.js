import { defineConfig } from 'vite'
import path from 'path'

// Optimized Vite config for performance
export default defineConfig({
  build: {
    outDir: 'public/assets',
    emptyOutDir: false, // don't wipe existing assets already in public/assets
    copyPublicDir: false, // avoid duplicating /public into /public/assets
    manifest: true, // Generate manifest.json for cache-busting hashed filenames
    // Optimize build for production
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true, // Remove console.log in production
        drop_debugger: true,
      },
    },
    // Enable source maps only in development
    sourcemap: false,
    // Optimize chunk size
    chunkSizeWarningLimit: 1000,
    // CSS code splitting
    cssCodeSplit: true,
    rollupOptions: {
      input: {
        'js/hero': path.resolve(__dirname, 'resources/js/hero.js'),
        'js/home': path.resolve(__dirname, 'resources/js/home.js'),
        'js/home-masonry': path.resolve(__dirname, 'resources/js/home-masonry.js'),
        'js/home-modern': path.resolve(__dirname, 'resources/js/home-modern.js'),
        'js/home-gallery': path.resolve(__dirname, 'resources/js/home-gallery.js'),
        'js/smooth-scroll': path.resolve(__dirname, 'resources/js/smooth-scroll.js'),
        'admin': path.resolve(__dirname, 'resources/admin.js'),
        'app': path.resolve(__dirname, 'resources/app.css'),
      },
      output: {
        // Use content hash in filenames for cache-busting
        // Manifest.json maps original names to hashed names for template resolution
        // Note: [name] includes the input key (e.g., 'js/hero'), so no prefix needed
        entryFileNames: '[name]-[hash].js',
        assetFileNames: '[name]-[hash][extname]',
        // Put chunks in js/ folder (not default assets/ to avoid /assets/assets/ path)
        chunkFileNames: 'js/[name]-[hash].js',
        // Optimize chunk splitting for better caching
        manualChunks: undefined,
      },
    },
  },
  // CSS optimization
  css: {
    devSourcemap: false,
  },
  // Server optimization for development
  server: {
    hmr: {
      overlay: false,
    },
  },
})
