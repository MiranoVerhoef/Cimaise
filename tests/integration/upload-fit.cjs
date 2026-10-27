const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const load = () => import(pathToFileURL(path.join(__dirname, '../../resources/js/utils/upload-fit.js')).href);

test('images within the pixel cap are left untouched', async () => {
  const { fitDimensions } = await load();
  assert.equal(fitDimensions(6000, 4000, 40_000_000), null); // 24 MP
  assert.equal(fitDimensions(8000, 5000, 40_000_000), null); // exactly 40 MP
  assert.equal(fitDimensions(9504, 6336, 0), null); // no limit configured
});

test('larger images are scaled to fit, keeping the aspect ratio', async () => {
  const { fitDimensions } = await load();
  for (const [w, h] of [[9504, 6336], [11648, 8736], [8256, 5504], [6400, 6400], [20000, 3000]]) {
    const fitted = fitDimensions(w, h, 40_000_000);
    assert.ok(fitted, `${w}x${h} must be scaled`);
    assert.ok(fitted.width * fitted.height <= 40_000_000, `${w}x${h} -> ${fitted.width}x${fitted.height}`);
    assert.ok(fitted.width * fitted.height > 39_900_000, 'no more resolution is lost than needed');
    assert.ok(Math.abs(fitted.width / fitted.height - w / h) < 0.001, 'aspect ratio is kept');
  }
});

test('a file the server accepts is never re-encoded', async () => {
  const { fitForUpload } = await load();
  globalThis.createImageBitmap = async () => ({ width: 6000, height: 4000, close() {} });
  try {
    const file = { size: 20 * 1024 * 1024, type: 'image/jpeg' };
    assert.equal(await fitForUpload(file, { maxPixels: 40_000_000, maxBytes: 32 * 1024 * 1024 }), null);
  } finally {
    delete globalThis.createImageBitmap;
  }
});
