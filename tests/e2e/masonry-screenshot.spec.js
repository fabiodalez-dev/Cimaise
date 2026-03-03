import { test } from '@playwright/test';

// Helper: scroll through page to trigger lazy loading, then force-reveal images
async function loadAllImages(page) {
  // Force lazy images to eager
  await page.evaluate(() => {
    document.querySelectorAll('img[loading="lazy"]').forEach(img => {
      img.removeAttribute('loading');
      if (!img.complete) {
        const src = img.getAttribute('src');
        if (src) { img.src = ''; img.src = src; }
      }
    });
  });

  // Scroll incrementally
  const height = await page.evaluate(() => document.body.scrollHeight);
  for (let y = 0; y < height; y += 300) {
    await page.evaluate((scrollY) => window.scrollTo(0, scrollY), y);
    await page.waitForTimeout(150);
  }
  await page.waitForTimeout(2000);

  // Force-reveal images that use opacity:0 + loaded class pattern
  await page.evaluate(() => {
    document.querySelectorAll('#images-gallery img, .gallery-section img, [data-layout] img').forEach(img => {
      img.classList.add('loaded', 'is-loaded');
      img.style.opacity = '1';
      const loading = img.closest('.img-container, .image-item, figure')?.querySelector('.loading');
      if (loading) loading.remove();
    });
    document.querySelectorAll('.lazy-card').forEach(el => {
      el.classList.remove('opacity-0');
      el.classList.add('card-revealed');
    });
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
}

// --- Home masonry ---
test('Screenshot masonry home with demo data', async ({ page }) => {
  test.setTimeout(30000);
  await page.goto('http://localhost:8000/?template=masonry');
  await page.waitForTimeout(3000);
  await page.screenshot({ path: 'test-results/masonry-demo-anon.png', fullPage: true });
});

// --- Galleries page ---
test('Screenshot galleries full page with demo data', async ({ page }) => {
  test.setTimeout(30000);
  await page.goto('http://localhost:8000/galleries');
  await page.waitForTimeout(2000);
  await loadAllImages(page);
  await page.screenshot({ path: 'test-results/galleries-demo-full.png', fullPage: true });
});

// --- Test each template on "Streets of Milan" (allow_template_switch=1, 6 images) ---
// template query param uses numeric IDs
const templates = [
  { id: 1, name: 'Grid Classica' },
  { id: 2, name: 'Masonry Portfolio' },
  { id: 3, name: 'Magazine Split' },
  { id: 4, name: 'Masonry Full' },
  { id: 5, name: 'Grid Compatta' },
  { id: 6, name: 'Grid Ampia (Creative)' },
  { id: 7, name: 'Gallery Wall Scroll' },
];

for (const tmpl of templates) {
  test(`Screenshot album "Streets of Milan" — ${tmpl.name}`, async ({ page }) => {
    test.setTimeout(45000);
    await page.setViewportSize({ width: 1280, height: 1600 });
    await page.goto(`http://localhost:8000/album/streets-of-milan?template=${tmpl.id}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await loadAllImages(page);
    await page.screenshot({ path: `test-results/album-milan-${tmpl.id}-${tmpl.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.png`, fullPage: true });
  });
}
