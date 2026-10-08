const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ baseURL: 'http://localhost:8080', viewport: { width: 1440, height: 1000 } });
  const login = await context.request.get('/admin/login');
  const response = await context.request.post('/admin/login', { form: { email: 'test@example.test', password: 'Test-pass-12345', csrf: login.headers()['x-csrf-token'] }, maxRedirects: 0 });
  assert.equal(response.status(), 302);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  async function saveForm(id) {
    const form = await page.locator(id).evaluate(element => ({ action: element.action, values: Object.fromEntries(new FormData(element)) }));
    const saved = await context.request.post(form.action, { form: form.values, maxRedirects: 0 });
    assert.equal(saved.status(), 302, await saved.text());
  }
  await page.goto('/admin/albums/1/edit');
  await page.locator('#show_equipment').uncheck();
  await saveForm('#album-form');
  await page.reload();
  assert.equal(await page.locator('#show_equipment').isChecked(), false);
  await page.locator('#show_equipment').check();
  await saveForm('#album-form');
  await page.reload();
  assert.equal(await page.locator('#show_equipment').isChecked(), true);
  console.log('PASS: equipment visibility can be saved, disabled and re-enabled');

  await page.goto('/admin/pages/home');
  await page.locator('[name=hero_enabled]').uncheck();
  await saveForm('#home-form');
  await page.reload();
  assert.equal(await page.locator('[name=hero_enabled]').isChecked(), false);
  await page.locator('[name=hero_enabled]').check();
  await saveForm('#home-form');
  console.log('PASS: Classic hero visibility persists and can be restored');

  await page.goto('/admin/media');
  const card = () => page.locator('[data-media-id]:not([data-media-id="999"])').first();
  await card().click();
  await page.locator('#media-sidebar').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  const links = page.locator('#image-versions-list a');
  assert.equal(await links.count(), 9);
  const image = await context.request.get(await links.first().getAttribute('href'));
  assert.equal(image.status(), 200);
  assert.match(image.headers()['content-type'], /^image\//);
  await page.locator('#media-sidebar-close').click();
  assert.equal(await page.locator('#media-sidebar').isVisible(), false);
  // Re-enter through actual SPA links: old window.openSidebar closures caused this failure.
  await page.locator('#sidebar a[href$="/admin/albums"]').click();
  await page.waitForURL('**/admin/albums');
  await page.locator('#sidebar a[href$="/admin/media"]').click();
  await page.waitForURL('**/admin/media');
  await card().waitFor();
  await card().focus();
  await page.keyboard.press('Enter');
  await page.locator('#media-sidebar').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  await page.locator('#image-job-progress').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('[data-job-text]').textContent.includes('retry automatically'));
  await page.screenshot({ path: '/tmp/cimaise-admin-gallery.png', fullPage: true });
  assert.deepEqual(errors, []);
  console.log('PASS: gallery click/keyboard, SPA re-entry, variant views and retry progress');
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
