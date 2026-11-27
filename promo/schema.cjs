// Schéma des présentations Docker Hub : docs/lupa-schema-remus.png et docs/lupa-schema-romulus.png
// docker run --rm -v "$PWD:/repo" -w /repo/promo lupa-promo node schema.cjs
const { chromium } = require('playwright');
const path = require('node:path');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1600, height: 500 }, deviceScaleFactor: 2 });
  for (const focus of ['remus', 'romulus']) {
    await page.goto(`file://${path.join(__dirname, 'schema.html')}?focus=${focus}`);
    await page.evaluate(() => document.fonts.ready);
    const out = path.join(__dirname, '..', 'docs', `lupa-schema-${focus}.png`);
    await page.screenshot({ path: out });
    console.log(out);
  }
  await browser.close();
})();
