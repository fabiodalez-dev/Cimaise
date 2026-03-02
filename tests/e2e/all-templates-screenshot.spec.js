import { test } from '@playwright/test';

// Helper: scroll and force-reveal all lazy images
async function loadAllImages(page) {
  await page.evaluate(() => {
    document.querySelectorAll('img[loading="lazy"]').forEach(img => {
      img.removeAttribute('loading');
      if (!img.complete) {
        const src = img.getAttribute('src');
        if (src) { img.src = ''; img.src = src; }
      }
    });
    // Reveal data-src images (galleries lazy cards)
    document.querySelectorAll('img[data-src]').forEach(img => {
      img.src = img.dataset.src;
      img.removeAttribute('data-src');
    });
  });

  const height = await page.evaluate(() => document.body.scrollHeight);
  for (let y = 0; y < height; y += 300) {
    await page.evaluate((scrollY) => window.scrollTo(0, scrollY), y);
    await page.waitForTimeout(150);
  }
  await page.waitForTimeout(2000);

  await page.evaluate(() => {
    // Force reveal all image patterns
    document.querySelectorAll('#images-gallery img, [data-layout] img, .gallery-section img, .masonry-item img').forEach(img => {
      img.classList.add('loaded', 'is-loaded');
      img.style.opacity = '1';
    });
    document.querySelectorAll('.loading').forEach(el => el.remove());
    document.querySelectorAll('.lazy-card').forEach(el => {
      el.classList.remove('opacity-0');
      el.classList.add('card-revealed');
    });
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
}

// ============================================================
// HOME TEMPLATES (6 variants)
// ============================================================
const homeTemplates = ['classic', 'modern', 'parallax', 'masonry', 'snap', 'gallery'];

for (const tmpl of homeTemplates) {
  test(`Home template: ${tmpl}`, async ({ page }) => {
    test.setTimeout(45000);
    await page.setViewportSize({ width: 1280, height: 1200 });
    await page.goto(`http://localhost:8000/?template=${tmpl}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await loadAllImages(page);
    await page.screenshot({ path: `test-results/home-${tmpl}.png`, fullPage: true });
  });
}

// ============================================================
// ALBUM GALLERY TEMPLATES (7 variants on "Streets of Milan")
// ============================================================
const albumTemplates = [
  { id: 1, name: 'grid-classica' },
  { id: 2, name: 'masonry-portfolio' },
  { id: 3, name: 'magazine-split' },
  { id: 4, name: 'masonry-full' },
  { id: 5, name: 'grid-compatta' },
  { id: 6, name: 'grid-ampia' },
  { id: 7, name: 'gallery-wall-scroll' },
];

for (const tmpl of albumTemplates) {
  test(`Album template: ${tmpl.name}`, async ({ page }) => {
    test.setTimeout(45000);
    await page.setViewportSize({ width: 1280, height: 1600 });
    await page.goto(`http://localhost:8000/album/streets-of-milan?template=${tmpl.id}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await loadAllImages(page);
    await page.screenshot({ path: `test-results/album-${tmpl.name}.png`, fullPage: true });
  });
}

// ============================================================
// GALLERIES PAGE
// ============================================================
test('Galleries page with all albums', async ({ page }) => {
  test.setTimeout(30000);
  await page.goto('http://localhost:8000/galleries');
  await page.waitForTimeout(2000);
  await loadAllImages(page);
  await page.screenshot({ path: 'test-results/galleries-full.png', fullPage: true });
});
