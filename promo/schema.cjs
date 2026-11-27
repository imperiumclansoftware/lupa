// Schéma de fonctionnement : docs/lupa-schema.png (README), lupa-schema-remus.png et -romulus.png (Docker Hub)
// docker run --rm -v "$PWD:/repo" -w /repo/promo lupa-promo node schema.cjs
const { chromium } = require('playwright');
const path = require('node:path');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1600, height: 500 }, deviceScaleFactor: 2 });
  // Sans mise en avant pour le README du dépôt, « cette image » sur chaque présentation Docker Hub
  for (const focus of ['', 'remus', 'romulus']) {
    await page.goto(`file://${path.join(__dirname, 'schema.html')}${focus ? `?focus=${focus}` : ''}`);
    await page.evaluate(() => document.fonts.ready);
    const out = path.join(__dirname, '..', 'docs', focus ? `lupa-schema-${focus}.png` : 'lupa-schema.png');
    await page.screenshot({ path: out });
    console.log(out);
  }
  await browser.close();
})();
