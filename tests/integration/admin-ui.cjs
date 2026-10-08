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
  await page.waitForFunction(() => document.getElementById('loading-indicator').classList.contains('hidden') && getComputedStyle(document.getElementById('page-content')).opacity === '1');
  await page.locator('#sidebar a[href$="/admin/media"]').click();
  await page.waitForURL('**/admin/media');
  await page.waitForFunction(() => document.getElementById('loading-indicator').classList.contains('hidden') && getComputedStyle(document.getElementById('page-content')).opacity === '1');
  await card().waitFor();
  await card().focus();
  await page.keyboard.press('Enter');
  await page.locator('#media-sidebar').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  await page.locator('#image-job-progress').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('[data-job-text]').textContent.includes('retry automatically'));
  assert.equal(await page.locator('#sidebar').getByText('Custom layouts', { exact: true }).count(), 1);
  assert.equal(await page.locator('#sidebar').getByText('Advanced statistics', { exact: true }).count(), 1);
  await page.waitForFunction(() => getComputedStyle(document.getElementById('page-content')).opacity === '1');
  await page.screenshot({ path: '/tmp/cimaise-admin-gallery.png', fullPage: true });
  assert.deepEqual(errors, []);
  console.log('PASS: gallery click/keyboard, SPA re-entry, variant views and retry progress');

  await page.goto('/admin/settings');
  const csrf = await page.locator('#settings-form input[name="csrf"]').inputValue();
  const uploadedLogo = await context.request.post('/admin/settings/logo-upload', {
    headers: { 'X-CSRF-Token': csrf },
    multipart: { file: { name: 'logo.jpg', mimeType: 'image/jpeg', buffer: await image.body() } }
  });
  assert.equal(uploadedLogo.status(), 200, await uploadedLogo.text());
  await page.reload();
  await page.locator('[name="maintenance_enabled"]').check();
  await page.locator('[name="maintenance_show_logo"]').check();
  await saveForm('#settings-form');
  const visitor = await browser.newContext({ baseURL: 'http://localhost:8080' });
  const maintenance = await visitor.newPage();
  const unavailable = await maintenance.goto('/');
  assert.equal(unavailable.status(), 503);
  assert.equal(unavailable.headers()['retry-after'], '3600');
  const logo = maintenance.locator('.logo img');
  assert.match(await logo.getAttribute('src'), /^\/media\/logo-/);
  await logo.evaluate(element => element.decode());
  assert.equal((await visitor.request.get(await logo.getAttribute('src'))).status(), 200);
  await page.goto('/admin/settings');
  assert.equal(await page.locator('[name="maintenance_show_logo"]').isChecked(), true);
  await page.locator('[name="maintenance_show_logo"]').uncheck();
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 503);
  assert.equal(await maintenance.locator('.logo img').count(), 0);
  await page.goto('/admin/settings');
  await page.locator('[name="maintenance_enabled"]').uncheck();
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 200);
  await visitor.close();
  console.log('PASS: maintenance settings save, visitor 503 and logo on/off with real upload');
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
