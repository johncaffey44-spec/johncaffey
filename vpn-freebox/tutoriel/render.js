// Convertit tutoriel.html en PDF A4 avec Chromium (Playwright).
// Usage : node render.js [sortie.pdf]
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const out = process.argv[2] || path.join(__dirname, 'Tutoriel-VPN-Freebox.pdf');
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('file://' + path.join(__dirname, 'tutoriel.html'), { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.pdf({
    path: out,
    format: 'A4',
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate:
      '<div style="width:100%;font-family:Inter,sans-serif;font-size:8px;color:#80868B;text-align:center;">' +
      'VPN Maison · Tutoriel Freebox · page <span class="pageNumber"></span> / <span class="totalPages"></span></div>',
    margin: { top: '14mm', bottom: '16mm', left: '14mm', right: '14mm' },
  });
  await browser.close();
  console.log('PDF écrit :', out);
})();
