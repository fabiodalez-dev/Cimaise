import { test } from '@playwright/test';
import { BASE } from './_helpers.js';

test.use({ viewport: { width: 1280, height: 900 } });

const HOMES = ['classic','modern','parallax','masonry','snap','gallery','editorial','justified','slideshow','split','bento','filmstrip'];
const PAGES = [
  ['galleries', '/galleries'],
  ['album', '/album/analog-days'],
  ['about', '/about'],
  ['search', '/search?q=a'],
  ['cookie', '/cookie'],
  ['privacy', '/privacy'],
  ['license', '/license'],
  ['404', '/this-page-does-not-exist-xyz'],
];

// Sample the effective (non-transparent) background colour at a grid of points.
// Returns % of UI-chrome sample points that are "light" (luminance > 0.6).
// Photos read as their dark container bg, so bright images don't false-trigger.
const PROBE = `(() => {
  function lum(c){ var m=c.match(/rgba?\\(([^)]+)\\)/); if(!m) return null; var p=m[1].split(',').map(parseFloat); if(p.length>=4 && p[3]===0) return null; var r=p[0]/255,g=p[1]/255,b=p[2]/255; return 0.2126*r+0.7152*g+0.0722*b; }
  function bgAt(x,y){ var el=document.elementFromPoint(x,y); var guard=0; while(el && guard<20){ var bg=getComputedStyle(el).backgroundColor; var l=lum(bg); if(l!==null) return l; el=el.parentElement; guard++; } return null; }
  var W=innerWidth,H=innerHeight,cols=24,rows=14,light=0,total=0,samples=[];
  for(var i=1;i<cols;i++) for(var j=1;j<rows;j++){ var x=W*i/cols, y=H*j/rows; var l=bgAt(x,y); if(l===null) continue; total++; if(l>0.6){ light++; } }
  return { lightPct: total? Math.round(100*light/total):0, total: total };
})()`;

test('dark-mode compatibility scan: all homes + pages', async ({ page }) => {
  // Turn on dark mode (visitor pref). addInitScript runs before any page
  // script on every navigation — immune to the execution-context-destroyed
  // race that a post-goto evaluate() hits when home immediately navigates.
  await page.addInitScript(() => localStorage.setItem('cimaise-theme', 'dark'));

  console.log('=== HOME TEMPLATES (dark) ===');
  for (const t of HOMES) {
    await page.goto(`${BASE}/?template=${t}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    const isDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    const r = await page.evaluate(PROBE);
    const flag = r.lightPct > 20 ? '  <-- SUSPECT' : '';
    console.log(`HOME ${t.padEnd(11)} darkClass=${isDark} lightBg=${r.lightPct}% (n=${r.total})${flag}`);
    if (r.lightPct > 20) await page.screenshot({ path: `/tmp/dc_home_${t}.png` });
  }

  console.log('=== PAGES (dark) ===');
  for (const [name, url] of PAGES) {
    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const isDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    const r = await page.evaluate(PROBE);
    const flag = r.lightPct > 20 ? '  <-- SUSPECT' : '';
    console.log(`PAGE ${name.padEnd(11)} darkClass=${isDark} lightBg=${r.lightPct}% (n=${r.total})${flag}`);
    if (r.lightPct > 20) await page.screenshot({ path: `/tmp/dc_page_${name}.png` });
  }
});
