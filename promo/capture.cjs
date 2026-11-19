// Captures réelles des interfaces Rémus et Romulus (thème sombre, 1440×900 à densité 2).
// Usage : USER=… PASS=… node capture.cjs  (dans le conteneur lupa-promo, réseau sas_edge)
const { chromium } = require('playwright');

(async () => {

const shots = {
  remus: [
    ['remus-dashboard', '/'],
    ['remus-list', 'list'],
    ['remus-security', '/securite'],
    ['remus-exports', '/exports'],
    ['remus-new-list', '/listes/nouvelle'],
    ['remus-vscode', '/listes/nouvelle'],
  ],
  romulus: [
    ['romulus-dashboard', '/'],
    ['romulus-packages', '/paquets'],
    ['romulus-import', '/import'],
    ['romulus-usage', '/utilisation'],
    ['romulus-vscode', '/utilisation'],
  ],
};
const hosts = { remus: 'http://collecte.localhost', romulus: 'http://miroir.localhost' };

const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP collecte.localhost caddy, MAP miroir.localhost caddy'] });
for (const [app, list] of Object.entries(shots)) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme: 'dark', locale: 'fr-FR' });
  const page = await context.newPage();
  // Connexion depuis la page (jeton anti-CSRF de la session, comme le front)
  await page.goto(hosts[app] + '/connexion');
  const status = await page.evaluate(async ([u, p]) => {
    await fetch('/api/session');
    const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/api/login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ username: u, password: p }) });
    return r.status;
  }, [process.env.USER, process.env.PASS]);
  if (200 !== status) throw new Error(`${app} : connexion refusée (${status})`);
  for (const [name, target] of list) {
    let path = target;
    if ('list' === target) {
      // Liste la plus parlante : le modèle Angular, sinon la plus fournie
      path = await page.evaluate(async () => {
        const { items } = await (await fetch('/api/lists')).json();
        const pick = items.find((l) => /angular/i.test(l.name)) ?? items.sort((a, b) => b.artifactCount - a.artifactCount)[0];
        return '/listes/' + pick.id;
      });
    }
    await page.goto(hosts[app] + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    if (name === 'remus-new-list') await page.getByText('Debian', { exact: true }).click().then(() => page.waitForTimeout(700));
    if (name === 'remus-vscode') {
      await page.getByText('VS Code', { exact: true }).click();
      await page.waitForTimeout(700);
      await page.getByText('Web (JavaScript, TypeScript)').click();
      await page.waitForTimeout(700);
    }
    if (name === 'romulus-usage') await page.getByRole('tab', { name: /Debian/ }).click().then(() => page.waitForTimeout(700));
    if (name === 'romulus-vscode') await page.getByRole('tab', { name: 'VS Code' }).click().then(() => page.waitForTimeout(700));
    await page.screenshot({ path: `assets/captures/${name}.png` });
    console.log('capture', name);
  }
  await context.close();
}
await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
