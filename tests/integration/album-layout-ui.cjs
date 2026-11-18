const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({headless: true});
  const context = await browser.newContext({baseURL: 'http://localhost:8080', viewport: {width: 1600, height: 1100}});
  const login = await context.request.get('/admin/login');
  const loggedIn = await context.request.post('/admin/login', {
    form: {email: 'test@example.test', password: 'Test-pass-12345', csrf: login.headers()['x-csrf-token']},
    maxRedirects: 0,
  });
  assert.equal(loggedIn.status(), 302);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/albums/create');
  await page.locator('[name="title"]').fill('Visual layout fixture');
  await page.locator('[name="slug"]').fill('visual-layout-fixture');
  const form = await page.locator('#album-form').evaluate(form => ({
    action: form.action, values: Object.fromEntries(new FormData(form)),
  }));
  const created = await context.request.post(form.action, {
    form: form.values, headers: {Accept: 'application/json'}, maxRedirects: 0,
  });
  assert.equal(created.status(), 200, await created.text());
  const album = await created.json();
  assert.ok(album.ok);
  // Create genuine JPEG bytes and exercise the standard upload endpoint.
  const jpeg = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 800; canvas.height = 500;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#38785d'; ctx.fillRect(0, 0, 800, 500);
    return canvas.toDataURL('image/jpeg').split(',')[1];
  });
  let uploadCount = 0;
  async function upload() {
    const token = await (await context.request.get('/admin/csrf-token')).json();
    const uploaded = await context.request.post('/admin/albums/' + album.id + '/upload', {
      headers: {'X-CSRF-Token': token.token},
      multipart: {file: {name: 'layout-' + (++uploadCount) + '.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg, 'base64')}},
    });
    const result = await uploaded.json();
    assert.ok(result.ok, JSON.stringify(result));
  }
  await upload();
  await upload();
  // Publish the fixture using the normal album form.
  await page.goto('/admin/albums/' + album.id + '/edit');
  await page.locator('[name="is_published"]').check();
  const publication = await page.locator('#album-form').evaluate(form => ({action: form.action, values: Object.fromEntries(new FormData(form))}));
  assert.equal((await context.request.post(publication.action, {form: publication.values, maxRedirects: 0})).status(), 302);
  await page.locator('a[href$="/layout"]').click();
  const frame = page.frameLocator('#layout-preview');
  await frame.locator('#layout-preview-templates').waitFor({state: 'attached'});
  const previewResponse = await context.request.get('/album/visual-layout-fixture?layout_preview=draft');
  assert.equal(previewResponse.headers()['x-frame-options'], 'SAMEORIGIN');
  assert.match(previewResponse.headers()['cache-control'], /no-store/);
  const visitor = await browser.newContext({baseURL: 'http://localhost:8080'});
  const publicPage = await visitor.newPage();
  await publicPage.goto('/album/visual-layout-fixture');
  assert.equal(await publicPage.locator('.album-layout').count(), 0, 'Normal uploads retain the default gallery');

  async function choose(type, index) {
    await page.locator('[data-add="' + type + '"]').click();
    await page.locator('#layout-picker-photos input').nth(index).check();
    await page.locator('[data-action="apply-picker"]').click();
  }
  await choose('photo', 0);
  await choose('grid', 1);
  await frame.locator('[data-layout-insert="text"]').last().click();
  await page.locator('[data-prop="text"]').fill('A personal story <script>window.badLayout = true</script>');
  await page.locator('[data-prop="text"]').blur();
  assert.equal(await frame.locator('.album-layout-text').innerText(), 'A personal story <script>window.badLayout = true</script>');
  assert.equal(await frame.locator('.album-layout-text script').count(), 0);
  await page.locator('[data-action="undo"]').click();
  await page.locator('[data-action="redo"]').click();
  await page.locator('[data-action="up"]').click();
  assert.equal(await frame.locator('[data-layout-block]').nth(1).getAttribute('data-block-type'), 'text');
  await frame.locator('[data-block-type="photo"]').click();
  await page.locator('[data-prop="max_width"]').fill('420');
  await page.locator('[data-prop="max_width"]').blur();
  const photoBox = await frame.locator('[data-block-type="photo"]').boundingBox();
  assert.ok(photoBox.width <= 420.5);
  await page.locator('[data-device="mobile"]').click();
  assert.ok((await page.locator('#layout-preview').boundingBox()).width <= 390.5);
  assert.ok(await frame.locator('html').evaluate(root => root.scrollWidth <= root.clientWidth + 1));
  await page.locator('[data-device="desktop"]').click();

  async function save(action) {
    const saved = page.waitForResponse(response => response.url().endsWith('/layout') && response.request().method() === 'POST');
    await page.locator('[data-action="' + action + '"]').click();
    const result = await saved;
    assert.equal(result.status(), 200, await result.text());
    await page.waitForFunction(() => !document.querySelector('[data-action="publish"]').disabled);
  }
  await save('draft');
  await publicPage.reload();
  assert.equal(await publicPage.locator('.album-layout').count(), 0, 'A draft does not alter the public album');
  // Anonymous requests cannot activate the private preview query.
  await publicPage.goto('/album/visual-layout-fixture?layout_preview=draft');
  assert.equal(await publicPage.locator('#layout-preview-templates').count(), 0);
  assert.equal(await publicPage.locator('.album-layout').count(), 0);
  await save('publish');
  await publicPage.goto('/album/visual-layout-fixture');
  await publicPage.locator('.album-layout-text').waitFor();
  assert.equal(await publicPage.locator('.album-layout .gallery-link').count(), 2);
  assert.equal(await publicPage.locator('.album-layout-text script').count(), 0);
  await publicPage.locator('.album-layout .gallery-link').first().click();
  await publicPage.locator('.pswp--open').waitFor();
  await publicPage.keyboard.press('Escape');
  await page.screenshot({path: '/tmp/cimaise-album-layout-editor.png', fullPage: true});
  await publicPage.screenshot({path: '/tmp/cimaise-album-layout-public.png', fullPage: true});
  await upload();
  await publicPage.reload();
  assert.equal(await publicPage.locator('[data-layout-remaining] .gallery-link').count(), 1, 'New uploads appear without editing');
  await page.locator('#layout-remaining').uncheck();
  await save('publish');
  await publicPage.reload();
  assert.equal(await publicPage.locator('.album-layout .gallery-link').count(), 2);
  page.once('dialog', dialog => dialog.accept());
  await save('default');
  await publicPage.reload();
  assert.equal(await publicPage.locator('.album-layout').count(), 0);
  assert.equal(await publicPage.locator('#images-gallery .gallery-link').count(), 3);
  assert.deepEqual(errors, []);
  console.log('PASS: normal uploads, live layout preview, responsive controls, undo/redo, draft isolation, publish, lightbox, new uploads and default restoration');
  await browser.close();
})().catch(error => {console.error(error); process.exit(1);});
