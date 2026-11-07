// Screenshots of the public site and admin screens, desktop and phone width.
//   node shoot.mjs [baseUrl] [outputFolder]
// Default: http://localhost:8200 (review site with example content) -> ../review/latest
import puppeteer from 'puppeteer-core';
import { mkdirSync, existsSync } from 'node:fs';

const base = process.argv[2] || 'http://localhost:8200';
const out = process.argv[3] || '../review/latest';
mkdirSync(out, { recursive: true });

const browserPath = [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find((p) => existsSync(p));

const publicPages = [
  ['01-accueil', '/'],
  ['02-rubrique-avec-cartes', '/nos-actions'],
  ['03-page-niveau-2', '/premiere-action-exemple'],
  ['04-page-niveau-3', '/detail-d-une-action-exemple'],
  ['05-page-documents', '/documents-exemple'],
  ['06-page-404', '/page-qui-n-existe-pas'],
];
const adminPages = [
  ['20-admin-pages', '/admin/pages'],
  ['21-admin-editeur-accueil', '/admin/pages/1'],
  ['22-admin-editeur-cartes', '/admin/pages/2'],
  ['23-admin-historique', '/admin/pages/2/historique'],
  ['24-admin-mediatheque', '/admin/medias'],
  ['25-admin-media', '/admin/medias/1'],
  ['27-admin-reglages', '/admin/reglages'],
  ['28-admin-utilisateurs', '/admin/utilisateurs'],
  ['29-admin-sauvegarde', '/admin/sauvegarde'],
  ['30-admin-supprimer-page', '/admin/pages/8/supprimer'],
];
const sizes = [['bureau', 1366, 900, false], ['mobile', 390, 844, true]];

const browser = await puppeteer.launch({ executablePath: browserPath, headless: true, args: ['--hide-scrollbars'] });
try {
  for (const [label, width, height, mobile] of sizes) {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1, isMobile: mobile, hasTouch: mobile });

    for (const [name, path] of publicPages) {
      await page.goto(base + path, { waitUntil: 'networkidle0' });
      await page.screenshot({ path: `${out}/${name}-${label}.png`, fullPage: true });
    }

    await page.goto(base + '/admin/auth/login', { waitUntil: 'networkidle0' });
    await page.screenshot({ path: `${out}/19-admin-connexion-${label}.png`, fullPage: true });
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('.dev-login button')]);

    for (const [name, path] of adminPages) {
      await page.goto(base + path, { waitUntil: 'networkidle0' });
      await page.screenshot({ path: `${out}/${name}-${label}.png`, fullPage: true });
    }

    // Media picker open from the page editor.
    await page.goto(base + '/admin/pages/2', { waitUntil: 'networkidle0' });
    await page.click('.block-cards .media-field-tools .btn');
    await page.waitForSelector('.picker-item');
    await new Promise((r) => setTimeout(r, 300));
    await page.screenshot({ path: `${out}/26-admin-choisir-image-${label}.png` });

    // Validation message: a video from a site that is not allowed.
    await page.goto(base + '/admin/pages/3', { waitUntil: 'networkidle0' });
    await page.$eval('.block-embed input', (input) => { input.value = 'https://www.dailymotion.com/video/x7tgad0'; input.dispatchEvent(new Event('input', { bubbles: true })); });
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.$eval('#page-form', (f) => f.requestSubmit())]);
    await page.screenshot({ path: `${out}/31-admin-erreur-video-${label}.png`, fullPage: false });

    // Log out so the next size starts clean.
    await page.goto(base + '/admin/pages', { waitUntil: 'networkidle0' });
    await Promise.all([page.waitForNavigation(), page.click('form[action="/admin/auth/logout"] button')]);
    await page.close();
  }
} finally {
  await browser.close();
}
console.log('Screenshots saved in ' + out);
