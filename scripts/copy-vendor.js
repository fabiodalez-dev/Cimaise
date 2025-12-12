/**
 * Copy vendor assets from node_modules to public/assets/vendor
 * Run automatically after npm install via postinstall script
 */

import { cpSync, mkdirSync, existsSync, rmSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..');
const nodeModules = join(root, 'node_modules');
const vendorDir = join(root, 'public', 'assets', 'vendor');

// Clean and recreate vendor directory
if (existsSync(vendorDir)) {
  rmSync(vendorDir, { recursive: true });
}
mkdirSync(vendorDir, { recursive: true });

// Define what to copy from each package
const copies = [
  // Bootstrap
  {
    from: '@fortawesome/fontawesome-free/css',
    to: 'fontawesome/css',
    files: ['all.min.css']
  },
  {
    from: '@fortawesome/fontawesome-free/webfonts',
    to: 'fontawesome/webfonts',
    recursive: true
  },
  // Bootstrap
  {
    from: 'bootstrap/dist/css',
    to: 'bootstrap/css',
    files: ['bootstrap.min.css']
  },
  {
    from: 'bootstrap/dist/js',
    to: 'bootstrap/js',
    files: ['bootstrap.bundle.min.js']
  },
  // Chart.js
  {
    from: 'chart.js/dist',
    to: 'chartjs',
    files: ['chart.umd.js']
  },
  // GSAP
  {
    from: 'gsap/dist',
    to: 'gsap',
    files: ['gsap.min.js', 'ScrollTrigger.min.js']
  },
  // imagesLoaded
  {
    from: 'imagesloaded',
    to: 'imagesloaded',
    files: ['imagesloaded.pkgd.min.js']
  },
  // jQuery
  {
    from: 'jquery/dist',
    to: 'jquery',
    files: ['jquery.min.js']
  },
  // Masonry
  {
    from: 'masonry-layout/dist',
    to: 'masonry',
    files: ['masonry.pkgd.min.js']
  },
  // Select2
  {
    from: 'select2/dist/css',
    to: 'select2',
    files: ['select2.min.css']
  },
  {
    from: 'select2/dist/js',
    to: 'select2',
    files: ['select2.min.js']
  },
  // SortableJS
  {
    from: 'sortablejs',
    to: 'sortablejs',
    files: ['Sortable.min.js']
  },
  // Swiper
  {
    from: 'swiper',
    to: 'swiper',
    files: ['swiper-bundle.min.js', 'swiper-bundle.min.css']
  },
  // TinyMCE (full directory needed)
  {
    from: 'tinymce',
    to: 'tinymce',
    recursive: true
  }
];

console.log('Copying vendor assets to public/assets/vendor...');

for (const copy of copies) {
  const srcDir = join(nodeModules, copy.from);
  const destDir = join(vendorDir, copy.to);

  if (!existsSync(srcDir)) {
    console.warn(`  Warning: ${copy.from} not found in node_modules`);
    continue;
  }

  mkdirSync(destDir, { recursive: true });

  if (copy.recursive) {
    // Copy entire directory
    cpSync(srcDir, destDir, { recursive: true });
    console.log(`  Copied ${copy.from} (full directory)`);
  } else if (copy.files) {
    // Copy specific files
    for (const file of copy.files) {
      const srcFile = join(srcDir, file);
      const destFile = join(destDir, file);
      if (existsSync(srcFile)) {
        cpSync(srcFile, destFile);
      } else {
        console.warn(`    Warning: ${file} not found in ${copy.from}`);
      }
    }
    console.log(`  Copied ${copy.files.length} file(s) from ${copy.from}`);
  }
}

console.log('Done! Vendor assets copied successfully.');
