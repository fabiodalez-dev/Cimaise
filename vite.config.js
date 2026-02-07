import { defineConfig } from 'vite'
import path from 'path'
import fs from 'fs'

// Plugin to clean stale hashed build artifacts while preserving vendor/ directory
function cleanStaleBuilds() {
  return {
    name: 'clean-stale-builds',
    buildStart() {
      const outDir = path.resolve(__dirname, 'public/assets')
      const jsDir = path.resolve(outDir, 'js')
      // Clean hashed files in root of outDir (e.g., admin-XXXX.js, app-XXXX.css)
      if (fs.existsSync(outDir)) {
        for (const file of fs.readdirSync(outDir)) {
          if (/^(admin|app|frontend)-[A-Za-z0-9_-]+\.(js|css)$/.test(file)) {
            fs.unlinkSync(path.join(outDir, file))
          }
        }
      }
      // Clean hashed files in js/ subfolder
      if (fs.existsSync(jsDir)) {
        for (const file of fs.readdirSync(jsDir)) {
          if (/^.+-[A-Za-z0-9_-]+\.js$/.test(file)) {
            fs.unlinkSync(path.join(jsDir, file))
          }
        }
      }
      // Clean old manifest
      const manifest = path.join(outDir, '.vite', 'manifest.json')
      if (fs.existsSync(manifest)) {
        fs.unlinkSync(manifest)
      }
    }
  }
}

// Optimized Vite config for performance
export default defineConfig({
  plugins: [cleanStaleBuilds()],
  build: {
    outDir: 'public/assets',
    emptyOutDir: false, // don't wipe vendor/ and other static assets
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
        'frontend': path.resolve(__dirname, 'resources/frontend.css'),
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
