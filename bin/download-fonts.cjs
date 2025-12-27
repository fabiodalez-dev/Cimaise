#!/usr/bin/env node

/**
 * Font Download Script for Cimaise Typography
 *
 * Downloads fonts from Google Fonts via google-webfonts-helper API
 * and saves them locally for GDPR compliance.
 *
 * Usage: node bin/download-fonts.cjs
 */

const https = require('https');
const fs = require('fs');
const path = require('path');

// Configuration
const FONTS_DIR = path.join(__dirname, '..', 'public', 'fonts');
const API_BASE = 'https://gwfh.mranftl.com/api/fonts';

// Special font name mappings (when slug → title case is incorrect)
const FONT_NAME_MAP = {
  'eb-garamond': 'EB Garamond',
  'dm-serif-display': 'DM Serif Display',
  'dm-sans': 'DM Sans',
  'pt-serif': 'PT Serif',
  'old-standard-tt': 'Old Standard TT',
};

const FONT_FAMILY_MAP = {};

// Fonts to download with their weights
const FONTS = {
  // Serif - Editorial
  'playfair-display': [400, 500, 600, 700],
  'cormorant-garamond': [400, 500, 600, 700],
  'eb-garamond': [400, 500, 600, 700],
  'libre-baskerville': [400, 700],
  'lora': [400, 500, 600, 700],
  'crimson-text': [400, 600, 700],
  'spectral': [400, 500, 600, 700],
  'domine': [400, 500, 600, 700],
  'old-standard-tt': [400, 700],
  'quattrocento': [400, 700],

  // Serif - Display
  'dm-serif-display': [400],
  'alegreya': [400, 500, 600, 700],
  'merriweather': [400, 700],
  'pt-serif': [400, 700],
  'abril-fatface': [400],
  'cinzel': [400, 500, 600, 700],
  'yeseva-one': [400],
  'della-respira': [400],
  'volkhov': [400, 700],

  // Serif - Modern
  'fraunces': [400, 500, 600, 700],
  'source-serif-4': [400, 600, 700],
  'crimson-pro': [400, 500, 600, 700],
  'newsreader': [400, 500, 600, 700],
  'bodoni-moda': [400, 500, 600, 700],
  'italiana': [400],

  // Sans - Clean
  'inter': [400, 500, 600, 700],
  'dm-sans': [400, 500, 700],
  'manrope': [400, 500, 600, 700],
  'plus-jakarta-sans': [400, 500, 600, 700],
  'noto-sans': [400, 500, 600, 700],

  // Sans - Geometric
  'urbanist': [400, 500, 600, 700],
  'space-grotesk': [400, 500, 600, 700],
  'sora': [400, 500, 600, 700],
  'archivo': [400, 500, 600, 700],
  'montserrat': [400, 500, 600, 700],

  // Sans - Readable
  'lexend': [400, 500, 600, 700],
  'roboto': [400, 500, 700],
  'open-sans': [400, 600, 700],
  'lato': [400, 700],
  'source-sans-3': [400, 600, 700],
};

// Create directory if not exists
function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

// Download file
function downloadFile(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    https.get(url, (response) => {
      if (response.statusCode === 301 || response.statusCode === 302) {
        // Follow redirect
        https.get(response.headers.location, (redirectResponse) => {
          redirectResponse.pipe(file);
          file.on('finish', () => {
            file.close();
            resolve();
          });
        }).on('error', reject);
      } else {
        response.pipe(file);
        file.on('finish', () => {
          file.close();
          resolve();
        });
      }
    }).on('error', (err) => {
      fs.unlink(dest, () => {});
      reject(err);
    });
  });
}

// Fetch JSON from API
function fetchJson(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (response) => {
      let data = '';
      response.on('data', (chunk) => data += chunk);
      response.on('end', () => {
        try {
          resolve(JSON.parse(data));
        } catch (e) {
          reject(e);
        }
      });
    }).on('error', reject);
  });
}

// Download a single font family
async function downloadFont(fontId, weights) {
  console.log(`\nDownloading: ${fontId}...`);
  const fontDir = path.join(FONTS_DIR, fontId);
  ensureDir(fontDir);

  try {
    // Get font info from API
    const subsets = 'latin,latin-ext';
    const apiUrl = `${API_BASE}/${fontId}?subsets=${subsets}`;
    const fontInfo = await fetchJson(apiUrl);

    if (!fontInfo || !fontInfo.variants) {
      console.log(`  Warning: Could not fetch font info for ${fontId}`);
      return false;
    }
    if (fontInfo.family) {
      FONT_FAMILY_MAP[fontId] = fontInfo.family;
    }

    // Filter variants by weight
    const variants = fontInfo.variants.filter(v =>
      weights.includes(parseInt(v.fontWeight)) && v.fontStyle === 'normal'
    );

    if (variants.length === 0) {
      console.log(`  Warning: No matching variants found for ${fontId}`);
      return false;
    }

    // Download each variant
    for (const variant of variants) {
      const weight = variant.fontWeight;
      const woff2Url = variant.woff2;

      if (woff2Url) {
        const filename = `${fontId}-${weight}.woff2`;
        const destPath = path.join(fontDir, filename);

        try {
          await downloadFile(woff2Url, destPath);
          console.log(`  ✓ ${filename}`);
        } catch (err) {
          console.log(`  ✗ ${filename}: ${err.message}`);
        }
      }
    }

    return true;
  } catch (err) {
    console.log(`  Error: ${err.message}`);
    return false;
  }
}

// Generate @font-face CSS
function generateFontFacesCss() {
  let css = '/* Cimaise Typography - Font Faces */\n';
  css += '/* Auto-generated by bin/download-fonts.cjs */\n\n';

  for (const [fontId, weights] of Object.entries(FONTS)) {
    const fontDir = path.join(FONTS_DIR, fontId);
    if (!fs.existsSync(fontDir)) continue;

    // Determine font family name (prefer API family, then mapping or slug title case)
    const fontName = FONT_FAMILY_MAP[fontId] || FONT_NAME_MAP[fontId] || fontId
      .split('-')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ');

    for (const weight of weights) {
      const filename = `${fontId}-${weight}.woff2`;
      const filePath = path.join(fontDir, filename);

      if (fs.existsSync(filePath)) {
        css += `@font-face {\n`;
        css += `  font-family: '${fontName}';\n`;
        css += `  font-style: normal;\n`;
        css += `  font-weight: ${weight};\n`;
        css += `  font-display: swap;\n`;
        css += `  src: url('./${fontId}/${filename}') format('woff2');\n`;
        css += `}\n\n`;
      }
    }
  }

  return css;
}

// Main function
async function main() {
  console.log('Cimaise Typography - Font Downloader');
  console.log('=====================================');
  console.log(`Target directory: ${FONTS_DIR}`);

  ensureDir(FONTS_DIR);

  let successCount = 0;
  let failCount = 0;

  for (const [fontId, weights] of Object.entries(FONTS)) {
    const success = await downloadFont(fontId, weights);
    if (success) {
      successCount++;
    } else {
      failCount++;
    }
  }

  // Generate CSS
  console.log('\n\nGenerating font-faces.css...');
  const css = generateFontFacesCss();
  fs.writeFileSync(path.join(FONTS_DIR, 'font-faces.css'), css);
  console.log('✓ font-faces.css created');

  console.log('\n=====================================');
  console.log(`Done! ${successCount} fonts downloaded, ${failCount} failed.`);
  console.log(`\nFont files saved to: ${FONTS_DIR}`);
  console.log('Include /fonts/font-faces.css in your HTML to use the fonts.');
}

main().catch(console.error);
