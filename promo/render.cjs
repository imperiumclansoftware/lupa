// Rendu de video.html image par image (temps déterministe via window.seek), assemblé par ffmpeg.
//   node render.cjs stills 1 8 15 …   → out/still-<t>.png (vérification)
//   node render.cjs video              → out/lupa.mp4 (1920×1080, 30 i/s, H.264)
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');

const FPS = 30, DURATION = 60;
(async () => {
  const [mode, ...times] = process.argv.slice(2);
  fs.mkdirSync('out', { recursive: true });
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
  await page.goto('file:///work/video.html');
  await page.evaluate(async () => { await document.fonts.ready; await Promise.all([...document.images].map((i) => i.decode())); });

  if ('stills' === mode) {
    for (const t of times) {
      await page.evaluate((s) => window.seek(s), Number(t));
      await page.screenshot({ path: `out/still-${t}.png` });
      console.log('image', t);
    }
  } else {
    const ffmpeg = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-',
      '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', 'out/lupa.mp4'], { stdio: ['pipe', 'inherit', 'inherit'] });
    const total = FPS * DURATION;
    for (let f = 0; f < total; f++) {
      await page.evaluate((s) => window.seek(s), f / FPS);
      const jpeg = await page.screenshot({ type: 'jpeg', quality: 95 });
      if (!ffmpeg.stdin.write(jpeg)) await new Promise((r) => ffmpeg.stdin.once('drain', r));
      if (f % 150 === 0) console.log(`image ${f} / ${total}`);
    }
    ffmpeg.stdin.end();
    await new Promise((r, j) => ffmpeg.on('close', (c) => (c === 0 ? r() : j(new Error('ffmpeg ' + c)))));
    // Image d'aperçu (affiche) pour GitHub ou une page web
    await page.evaluate(() => window.seek(10.5));
    await page.screenshot({ path: 'out/lupa-poster.png' });
    console.log('vidéo prête : out/lupa.mp4');
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
