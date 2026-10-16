// Captures réelles des interfaces Rémus et Romulus (thème sombre, 1440×900 à densité 2).
// Usage : USER=… PASS=… node capture.cjs  (dans le conteneur lupa-promo, réseau sas_edge)
const { chromium } = require('playwright');

(async () => {

const shots = {
  remus: [
    ['remus-dashboard', '/'],
    ['remus-list', '/listes/6'],
    ['remus-security', '/securite'],
    ['remus-exports', '/exports'],
    ['remus-new-list', '/listes/nouvelle'],
  ],
  romulus: [
    ['romulus-dashboard', '/'],
    ['romulus-packages', '/paquets'],
    ['romulus-import', '/import'],
    ['romulus-usage', '/utilisation'],
  ],
};
const hosts = { remus: 'http://collecte.localhost', romulus: 'http://miroir.localhost' };

const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP collecte.localhost caddy, MAP miroir.localhost caddy'] });
for (const [app, list] of Object.entries(shots)) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme: 'dark', locale: 'fr-FR' });
  const page = await context.newPage();
  // Connexion depuis la page : la résolution des noms (règle d'hôtes) est celle de Chromium
  await page.goto(hosts[app] + '/connexion');
  const status = await page.evaluate(async ([u, p]) => (await fetch('/api/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: u, password: p }) })).status, [process.env.USER, process.env.PASS]);
  if (200 !== status) throw new Error(`${app} : connexion refusée (${status})`);
  for (const [name, path] of list) {
    await page.goto(hosts[app] + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    if (name === 'remus-new-list') await page.getByText('Debian', { exact: true }).click().then(() => page.waitForTimeout(600));
    if (name === 'romulus-usage') await page.getByRole('tab', { name: /Debian/ }).click().then(() => page.waitForTimeout(600));
    await page.screenshot({ path: `assets/captures/${name}.png` });
    console.log('capture', name);
  }
  await context.close();
}
await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
