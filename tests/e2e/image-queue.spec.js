// @ts-check
// Uploaded images get their variants from the durable image job queue.
//
// The upload answers as soon as the original and the small preview are stored;
// the breakpoint × format matrix is generated afterwards (after the response
// on PHP-FPM, by the image worker in Docker). These checks read the local
// filesystem, so they only run against a server on this machine.
import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import {
    BASE, createAlbum, deleteAlbum, requireServer, requireAdmin,
    getAlbumCsrf, uploadBuffer, makeJpegBuffer,
} from './_helpers.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const JOBS = path.join(ROOT, 'storage', 'image-jobs');
const MEDIA = path.join(ROOT, 'public', 'media');
const LOCAL = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/.test(BASE);

const variants = (imageId) => fs.readdirSync(MEDIA).filter((f) => f.startsWith(`${imageId}_`));
const jobPending = (imageId) => fs.existsSync(JOBS) && fs.readdirSync(JOBS).includes(`${imageId}.job`);

test.describe.serial('Image job queue', () => {
    test.skip(!LOCAL, 'reads the server filesystem');
    let page;
    let album = { id: null, slug: null };

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        album = await createAlbum(page, `IQ ${Date.now()}`);
    });

    test.afterAll(async () => {
        try {
            if (album.id) await deleteAlbum(page, album.id);
        } finally {
            await page.close();
        }
    });

    /** Serialize the settings form as the browser would (unchecked boxes omitted). */
    const settingsForm = async () => {
        await page.goto(`${BASE}/admin/settings`, { waitUntil: 'load' });
        return page.$eval('#settings-form', (f) => {
            const out = {};
            for (const [k, v] of new FormData(f).entries()) if (typeof v === 'string') out[k] = v;
            return out;
        });
    };
    const saveSettings = async (form) => {
        const r = await page.request.post(`${BASE}/admin/settings`, { form, maxRedirects: 0, failOnStatusCode: false });
        expect(r.status(), 'POST /admin/settings').toBe(302);
    };
    const setAsync = async (orig, enabled) => {
        const { variants_async: _omit, ...rest } = orig;
        await saveSettings(enabled ? { ...rest, variants_async: 'on' } : rest);
    };
    const upload = async (color) => {
        const csrf = await getAlbumCsrf(page, album.id);
        const buffer = await makeJpegBuffer(page, 1600, 1200, color);
        const r = await uploadBuffer(page, album.id, { name: `iq-${color.slice(1)}.jpg`, mimeType: 'image/jpeg', buffer }, csrf);
        expect(r.status(), 'upload accepted').toBe(200);
        const data = await r.json();
        expect(data.ok).toBe(true);
        return data.id;
    };
    const expectFullMatrix = (id) => {
        const files = variants(id);
        for (const size of ['sm', 'md', 'lg', 'xl', 'xxl']) {
            expect(files, `image ${id}: ${size}.jpg`).toContain(`${id}_${size}.jpg`);
        }
        expect(files.some((f) => f.endsWith('.webp')), `image ${id}: a webp variant`).toBe(true);
    };

    // No request for a variant URL is made in these tests, so nothing they
    // find on disk can come from the on-demand media route.

    test('IQ-01: background mode queues the upload and the queue empties itself', async () => {
        test.skip(!album.id, 'album fixture unavailable');
        test.setTimeout(180000);
        const orig = await settingsForm();
        try {
            await setAsync(orig, true);
            const ids = [];
            for (const color of ['#ef4444', '#22c55e', '#3b82f6']) {
                ids.push(await upload(color));
            }
            await expect.poll(() => ids.filter(jobPending).length, { timeout: 150000, intervals: [500] }).toBe(0);
            ids.forEach(expectFullMatrix);
        } finally {
            await saveSettings(orig);
        }
    });

    test('IQ-02: foreground mode answers only once the variants exist', async () => {
        test.skip(!album.id, 'album fixture unavailable');
        test.setTimeout(120000);
        const orig = await settingsForm();
        try {
            await setAsync(orig, false);
            const id = await upload('#a855f7');
            expect(jobPending(id), 'nothing left for the queue').toBe(false);
            expectFullMatrix(id);
        } finally {
            await saveSettings(orig);
        }
    });

    test('IQ-03: the original downloads only while the album allows it', async ({ browser }) => {
        test.skip(!album.id, 'album fixture unavailable');
        const id = await upload('#f59e0b');
        await page.goto(`${BASE}/admin/albums/${album.id}/edit`, { waitUntil: 'load' });
        const form = await page.$eval('#album-form', (f) => {
            const out = {};
            for (const [k, v] of new FormData(f).entries()) if (typeof v === 'string') out[k] = v;
            return out;
        });
        const { allow_downloads: _omit, ...withoutDownloads } = form;
        const save = async (fields) => {
            const r = await page.request.post(`${BASE}/admin/albums/${album.id}`, { form: fields, maxRedirects: 0, failOnStatusCode: false });
            expect(r.status(), 'album saved').toBe(302);
        };
        const anon = await browser.newContext();
        try {
            await save({ ...withoutDownloads, is_published: 'on', allow_downloads: 'on' });
            const ok = await anon.request.get(`${BASE}/download/image/${id}`);
            expect(ok.status()).toBe(200);
            expect(ok.headers()['content-type']).toBe('image/jpeg');
            expect(ok.headers()['content-disposition']).toContain('attachment');
            expect((await ok.body()).length).toBeGreaterThan(1000);

            await save({ ...withoutDownloads, is_published: 'on' });
            const denied = await anon.request.get(`${BASE}/download/image/${id}`);
            expect(denied.status()).toBe(403);
        } finally {
            await anon.close();
        }
    });
});
