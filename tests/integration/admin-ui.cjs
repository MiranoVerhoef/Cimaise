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
  const links = page.locator('#image-versions-list a[href*="/variants/"]');
  assert.equal(await links.count(), 9);
  assert.equal(await page.locator('#original-image-title').textContent(), 'Original Image');
  assert.equal(await page.locator('#original-image-details a').count(), 1);
  assert.equal(await page.locator('#image-versions-list a[href$="/original"]').count(), 0);
  const firstId = await page.locator('#sidebar-image-id').inputValue();
  await page.keyboard.press('ArrowRight');
  assert.notEqual(await page.locator('#sidebar-image-id').inputValue(), firstId);
  await page.keyboard.press('ArrowLeft');
  assert.equal(await page.locator('#sidebar-image-id').inputValue(), firstId);
  await page.locator('#sidebar-alt').focus();
  await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#sidebar-image-id').inputValue(), firstId);
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  const image = await context.request.get(await page.locator('#image-versions-list a[href$=".jpg"]').first().getAttribute('href'));
  assert.equal(image.status(), 200);
  assert.match(image.headers()['content-type'], /^image\//);
  const webpLogo = await context.request.get(await page.locator('#image-versions-list a[href$=".webp"]').first().getAttribute('href'));
  assert.equal(webpLogo.status(), 200);
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
    multipart: { file: { name: 'logo.webp', mimeType: 'image/webp', buffer: await webpLogo.body() } }
  });
  assert.equal(uploadedLogo.status(), 200, await uploadedLogo.text());
  await page.reload();
  // The upload fixture uses tiny breakpoints for speed; settings require >=100.
  for (const [index, size] of ['sm', 'md', 'lg', 'xl', 'xxl'].entries()) {
    await page.locator(`[name="bp_${size}"]`).fill(String((index + 1) * 100));
  }
  const visitor = await browser.newContext({ baseURL: 'http://localhost:8080' });
  const maintenance = await visitor.newPage();
  assert.equal((await maintenance.goto('/')).status(), 200);
  await maintenance.waitForFunction(() => !!navigator.serviceWorker.controller);
  assert.equal((await maintenance.goto('/')).status(), 200);
  await page.locator('label').filter({ has: page.locator('[name="maintenance_enabled"]') }).click();
  assert.equal(await page.locator('[name="maintenance_enabled"]').isChecked(), true);
  await page.locator('[name="maintenance_show_logo"]').check();
  await page.locator('[name="maintenance_show_admin_login"]').check();
  await saveForm('#settings-form');
  await page.reload();
  assert.equal(await page.locator('[name="maintenance_enabled"]').isChecked(), true);
  const unavailable = await maintenance.goto('/');
  assert.equal(unavailable.status(), 503);
  assert.equal(unavailable.headers()['retry-after'], '3600');
  assert.match(unavailable.headers()['cache-control'], /no-store/);
  assert.equal(await maintenance.locator('.login-link').count(), 1);
  assert.equal(await maintenance.evaluate(async () => {
    const keys = await caches.keys();
    const counts = await Promise.all(keys.filter(key => key.endsWith('-pages')).map(async key => (await (await caches.open(key)).keys()).length));
    return counts.reduce((sum, count) => sum + count, 0);
  }), 0);
  const logo = maintenance.locator('.logo img');
  assert.match(await logo.getAttribute('src'), /^\/media\/logo-/);
  await logo.evaluate(element => element.decode());
  assert.equal((await visitor.request.get(await logo.getAttribute('src'))).status(), 200);
  await page.goto('/admin/settings');
  assert.equal(await page.locator('[name="maintenance_show_logo"]').isChecked(), true);
  await page.locator('[name="maintenance_show_logo"]').uncheck();
  await page.locator('[name="maintenance_show_admin_login"]').uncheck();
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 503);
  assert.equal(await maintenance.locator('.logo img').count(), 0);
  assert.equal(await maintenance.locator('.login-link').count(), 0);
  assert.equal((await visitor.request.get('/admin/login')).status(), 200);
  await page.goto('/admin/settings');
  await page.locator('[name="maintenance_show_admin_login"]').check();
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 503);
  assert.equal(await maintenance.locator('.login-link').count(), 1);
  await page.goto('/admin/settings');
  await page.locator('label').filter({ has: page.locator('[name="maintenance_enabled"]') }).click();
  assert.equal(await page.locator('[name="maintenance_enabled"]').isChecked(), false);
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 200);
  await visitor.close();
  console.log('PASS: maintenance settings save, visitor 503 and logo on/off with real upload');
  console.log('PASS: maintenance replaces warmed pages, clears offline HTML and supports admin login visibility');

  await page.goto('/admin/albums/1/edit');
  await page.locator('[name="is_published"]').check();
  await page.locator('[name="allow_downloads"]').check();
  await saveForm('#album-form');
  await page.reload();
  // JPEG metadata is deliberately large: the former client compressor stripped it.
  const comment = Buffer.alloc(8192, 65);
  const originalBytes = Buffer.concat([(await image.body()).subarray(0, 2), Buffer.from([255, 254, 32, 2]), comment, (await image.body()).subarray(2)]);
  let releaseUpload;
  let processingId;
  let showProcessing = true;
  const uploadGate = new Promise(resolve => { releaseUpload = resolve; });
  await page.route('**/admin/albums/1/upload', async route => {
    const response = await route.fetch();
    processingId = (await response.json()).id;
    await uploadGate;
    await route.fulfill({ response });
  });
  await page.route('**/admin/api/image-jobs', async route => {
    const response = await route.fetch();
    const body = await response.json();
    if (showProcessing && processingId) {
      const job = body.jobs.find(job => job.id === processingId);
      if (job) Object.assign(job, { pending: true, state: 'processing', completed: 4, total: 10, current: 'md.webp' });
    }
    await route.fulfill({ response, json: body });
  });
  const uploadResponse = page.waitForResponse(response => response.url().endsWith('/admin/albums/1/upload') && response.request().method() === 'POST');
  await page.locator('#uppy input[type="file"]').setInputFiles({ name: 'preserved-original.jpg', mimeType: 'image/jpeg', buffer: originalBytes });
  await page.waitForFunction(() => document.getElementById('upload-status').textContent.includes('Creating versions: 4/10'));
  assert.equal(await page.locator('#upload-progress').isVisible(), true);
  assert.equal(await page.locator('#upload-counter').textContent(), '0 / 1');
  assert.match(await page.locator('#upload-file-list').textContent(), /md.webp/);
  showProcessing = false;
  releaseUpload();
  const uploadData = await (await uploadResponse).json();
  await page.waitForFunction(() => document.getElementById('upload-counter').textContent === '1 / 1');
  await page.unroute('**/admin/albums/1/upload');
  await page.unroute('**/admin/api/image-jobs');
  console.log('PASS: upload panel tracks correlated generation before HTTP upload completion and waits for versions');
  assert.equal(uploadData.ok, true, JSON.stringify(uploadData));
  const originalDownload = await context.request.get(`/admin/media/images/${uploadData.id}/original`);
  assert.equal(originalDownload.status(), 200);
  assert.match(originalDownload.headers()['content-disposition'], /^attachment;/);
  assert.deepEqual(await originalDownload.body(), originalBytes);
  const anonymous = await browser.newContext({ baseURL: 'http://localhost:8080' });
  const publicDownload = await anonymous.request.get(`/download/image/${uploadData.id}`);
  assert.equal(publicDownload.status(), 200);
  assert.deepEqual(await publicDownload.body(), originalBytes);
  const details = await (await context.request.get(`/admin/media/images/${uploadData.id}/variants`)).json();
  assert.equal(details.original.bytes, originalBytes.length);
  const denied = await anonymous.request.get(`/admin/media/images/${uploadData.id}/original`, { maxRedirects: 0 });
  assert.equal(denied.status(), 302);
  console.log('PASS: real browser upload preserves bytes; public/admin original downloads match and report size');

  const publicPage = await anonymous.newPage();
  publicPage.on('pageerror', error => errors.push(error.message));
  await publicPage.goto('/album/test');
  await publicPage.waitForFunction(() => !!window.__pswpLightbox);
  await publicPage.locator(`.pswp-gallery a[data-image-id="${uploadData.id}"]`).first().click();
  const downloadButton = publicPage.locator('.pswp__button--download-button');
  await downloadButton.waitFor({ state: 'visible' });
  await publicPage.route('**/download/image/*', route => route.fulfill({ status: 403, contentType: 'text/html', body: 'No permission' }));
  const deniedDialog = publicPage.waitForEvent('dialog');
  await downloadButton.click();
  const dialog = await deniedDialog;
  assert.match(dialog.message(), /Unable to download the original/);
  await dialog.accept();
  await publicPage.unroute('**/download/image/*');
  const downloadEvent = publicPage.waitForEvent('download');
  await downloadButton.click();
  const browserDownload = await downloadEvent;
  assert.deepEqual(await require('node:fs/promises').readFile(await browserDownload.path()), originalBytes);
  await publicPage.close();
  console.log('PASS: incognito lightbox downloads the original and displays denied responses instead of saving HTML');

  await page.goto('/admin/albums/1/edit');
  await page.locator('[name="allow_downloads"]').uncheck();
  await page.locator('#album-categories').selectOption([]);
  await saveForm('#album-form');
  await page.reload();
  assert.equal(await page.locator('#album-categories option:checked').textContent(), 'None');
  assert.equal(await page.locator('#album-categories option').filter({ hasText: /^None$/ }).count(), 1);
  assert.equal((await anonymous.request.get(`/download/image/${uploadData.id}`)).status(), 403);
  assert.equal((await context.request.get(`/admin/media/images/${uploadData.id}/original`)).status(), 200);
  console.log('PASS: albums without a selected category use the None fallback');
  await page.goto('/admin/media?album=1&format=jpeg&generation=generated&q=preserved');
  assert.equal(await page.locator('#media-filters [name="album"]').inputValue(), '1');
  assert.equal(await page.locator('#media-filters [name="format"]').inputValue(), 'jpeg');
  assert.equal(await page.locator('[data-media-id]').count(), 0); // Search remains combined with every filter.
  await page.goto('/admin/media?album=1&format=jpeg&generation=generated');
  assert.ok(await page.locator('[data-media-id]').count() > 0);
  await page.goto('/admin/media?format=png');
  assert.equal(await page.locator('[data-media-id]').count(), 0);
  await page.goto('/admin/media?generation=retrying');
  assert.equal(await page.locator('[data-media-id="999"]').count(), 1);
  console.log('PASS: album, format, search and live retry filters combine correctly');

  await page.goto('/admin/pages/home');
  await page.locator('[name="hero_enabled"]').check();
  await page.locator('[name="hero_title"]').fill('Only this title');
  await page.locator('[name="hero_subtitle"]').fill('Only this text');
  await page.locator('[name="gallery_per_album"]').fill('2');
  await page.locator('[name="hero_show_title"]').check();
  await page.locator('[name="hero_show_text"]').uncheck();
  await saveForm('#home-form');
  let home = await anonymous.request.get('/');
  assert.match(await home.text(), /Only this title/);
  assert.doesNotMatch(await home.text(), /Only this text/);
  const oldEtag = home.headers().etag;
  assert.match(home.headers()['cache-control'], /no-cache/);
  const homeBrowser = await anonymous.newPage();
  await homeBrowser.goto('/');
  await homeBrowser.waitForFunction(() => !!navigator.serviceWorker.controller);
  assert.match(await homeBrowser.locator('body').textContent(), /Only this title/);
  await page.reload();
  assert.equal(await page.locator('[name="gallery_per_album"]').inputValue(), '2');
  await page.locator('[name="hero_show_title"]').uncheck();
  await page.locator('[name="hero_show_text"]').check();
  await saveForm('#home-form');
  home = await anonymous.request.get('/', { headers: oldEtag ? { 'If-None-Match': oldEtag } : {} });
  assert.equal(home.status(), 200);
  assert.doesNotMatch(await home.text(), /Only this title/);
  assert.match(await home.text(), /Only this text/);
  await homeBrowser.goto('/'); // Ordinary navigation through the installed service worker.
  assert.doesNotMatch(await homeBrowser.locator('body').textContent(), /Only this title/);
  assert.match(await homeBrowser.locator('body').textContent(), /Only this text/);
  await homeBrowser.reload();
  assert.match(await homeBrowser.locator('body').textContent(), /Only this text/);
  await homeBrowser.close();
  console.log('PASS: warmed browser/service-worker pages show saved title changes on normal navigation and reload');
  console.log('PASS: title/text controls update the next anonymous request without stale HTML or 304');

  await page.goto('/admin/typography');
  const fontBytes = await (await context.request.get('/fonts/inter/inter-400.woff2')).body();
  const fontCsrf = await page.locator('#font-upload-form input[name="csrf"]').inputValue();
  const fontUpload = await context.request.post('/admin/typography/upload', {
    multipart: { csrf: fontCsrf, font_name: 'Uploaded Test', font_weight: '400', font_type: 'sans',
      font_file: { name: 'test.woff2', mimeType: 'font/woff2', buffer: fontBytes } }, maxRedirects: 0
  });
  assert.equal(fontUpload.status(), 302);
  await page.reload();
  const fontOption = page.locator('select[name="body_font"] option').filter({ hasText: 'Uploaded Test' });
  assert.equal(await fontOption.count(), 1);
  await page.locator('select[name="body_font"]').selectOption(await fontOption.getAttribute('value'));
  await saveForm('#typography-form');
  const fontCss = await (await anonymous.request.get('/fonts/typography.css')).text();
  const customFontUrl = fontCss.match(/url\('(\/fonts\/custom\/[^']+)'\)/)[1];
  const customFont = await anonymous.request.get(customFontUrl);
  assert.equal(customFont.status(), 200);
  assert.deepEqual(await customFont.body(), fontBytes);
  const homeWithFont = await (await anonymous.request.get('/')).text();
  assert.match(homeWithFont, /typography\.css\?v=[a-f0-9]{16}/);
  assert.match(homeWithFont, /-webkit-touch-callout: none/);
  const mobile = await browser.newContext({ baseURL: 'http://localhost:8080', isMobile: true, hasTouch: true });
  const mobilePage = await mobile.newPage();
  await mobilePage.goto('/');
  assert.equal(await mobilePage.evaluate(() => {
    const image = document.createElement('img'); document.body.appendChild(image);
    return image.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true }));
  }), false);
  await mobile.close();
  await anonymous.close();
  console.log('PASS: uploaded fonts are selectable and served unchanged with refreshed CSS URLs; mobile callouts suppressed');
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
