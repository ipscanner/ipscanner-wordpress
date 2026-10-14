const { chromium } = require('playwright');
const path = require('path');

const out = process.argv[2] || path.join(__dirname, '..');
(async () => {
  const b = await chromium.launch();
  for (const [w, h, zoom] of [[1544, 500, 1], [772, 250, 0.5]]) {
    const p = await b.newPage({ viewport: { width: w, height: h } });
    await p.goto('file://' + path.join(__dirname, 'banner.html'));
    await p.evaluate((z) => document.body.style.setProperty('--zoom', z), zoom);
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(out, `banner-${w}x${h}.png`), omitBackground: true });
  }
  await b.close();
})();
