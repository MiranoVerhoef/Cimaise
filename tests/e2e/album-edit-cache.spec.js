// @ts-check
// An album edited in the admin must show its new content to anonymous
// visitors at once.
//
// Regression: page-cache invalidation is soft (the row is only marked
// expired) and the album / category / tag pages served that stale row with no
// rebuild trigger, so an edit stayed invisible to logged-out visitors until
// the expired row was garbage-collected a day later. Admins never saw it
// because they bypass the page cache.
import { test, expect } from '@playwright/test';
import {
    BASE, createAlbum, uploadCover, deleteAlbum, requireServer, requireAdmin,
} from './_helpers.js';

/** Serialize the album form as the browser would (unchecked boxes omitted). */
async function readAlbumForm(page, id) {
    await page.goto(`${BASE}/admin/albums/${id}/edit`, { waitUntil: 'load' });
    return page.$eval('#album-form', (f) => {
        const out = {};
        for (const [k, v] of new FormData(f).entries()) if (typeof v === 'string') out[k] = v;
        return out;
    });
}
async function saveAlbum(page, id, form) {
    const r = await page.request.post(`${BASE}/admin/albums/${id}`, { form, maxRedirects: 0, failOnStatusCode: false });
    expect(r.status(), `POST /admin/albums/${id}`).toBe(302);
}
async function anonBody(browser, path) {
    const ctx = await browser.newContext();
    try {
        const r = await ctx.request.get(`${BASE}${path}`);
        expect(r.status(), `GET ${path}`).toBe(200);
        return await r.text();
    } finally {
        await ctx.close();
    }
}

test.describe.serial('Album edits reach anonymous visitors immediately', () => {
    let page;
    let album = { id: null, slug: null };
    let form;

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        album = await createAlbum(page, `ECA ${Date.now()}`);
        if (album.id) {
            await uploadCover(page, album.id, 'ECA', '#0d9488');
            form = await readAlbumForm(page, album.id);
        }
    });

    test.afterAll(async () => {
        try {
            if (album.id) await deleteAlbum(page, album.id);
        } finally {
            await page.close();
        }
    });

    test('ECA-01: a changed excerpt replaces the cached page body', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        const first = `eca-first-${Date.now()}`;
        const second = `eca-second-${Date.now()}`;
        // The excerpt also feeds <meta description>, which is recomputed per
        // request; only the <p> in the page body comes from the cached data.
        const inBody = (html, text) => new RegExp(`<p[^>]*>\\s*${text}\\s*</p>`).test(html);

        await saveAlbum(page, album.id, { ...form, is_published: 'on', excerpt: first });
        let body = await anonBody(browser, `/album/${album.slug}`);
        expect(inBody(body, first), 'first excerpt rendered').toBe(true);
        body = await anonBody(browser, `/album/${album.slug}`); // served from cache
        expect(inBody(body, first)).toBe(true);

        await saveAlbum(page, album.id, { ...form, is_published: 'on', excerpt: second });
        body = await anonBody(browser, `/album/${album.slug}`);
        expect(inBody(body, second), 'edit visible on the very next anonymous request').toBe(true);
        expect(inBody(body, first), 'stale excerpt gone').toBe(false);
    });

    test('ECA-02: the rebuilt page is cached again (no rebuild on every hit)', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        const a = await anonBody(browser, `/album/${album.slug}`);
        const b = await anonBody(browser, `/album/${album.slug}`);
        const excerpt = (html) => (html.match(/eca-second-\d+/) ?? [null])[0];
        expect(excerpt(a)).not.toBeNull();
        expect(excerpt(b)).toBe(excerpt(a));
    });

    test('ECA-03: the ETag follows the page that is actually rendered', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        // The album ETag must be derived from the same cache entry the page is
        // rendered from. It used to come from a separate, partially built
        // entry that a settings change never touched, so a returning visitor
        // got 304 Not Modified for a page whose layout had changed.
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
        const orig = await settingsForm();
        const ctx = await browser.newContext();
        try {
            await saveSettings({ ...orig, gallery_page_template: 'classic' });
            await saveAlbum(page, album.id, { ...form, is_published: 'on', excerpt: 'eca-etag' });

            const first = await ctx.request.get(`${BASE}/album/${album.slug}`);
            expect(first.status()).toBe(200);
            const etag = first.headers()['etag'];
            expect(etag, 'album page sends an ETag').toBeTruthy();

            const again = await ctx.request.get(`${BASE}/album/${album.slug}`, { headers: { 'If-None-Match': etag } });
            expect(again.status(), 'unchanged page revalidates with 304').toBe(304);

            await saveSettings({ ...orig, gallery_page_template: 'hero' });
            const changed = await ctx.request.get(`${BASE}/album/${album.slug}`, { headers: { 'If-None-Match': etag } });
            expect(changed.status(), 'changed page must not answer 304').toBe(200);
            expect(changed.headers()['etag']).not.toBe(etag);
        } finally {
            await ctx.close();
            await saveSettings(orig);
        }
    });
});
