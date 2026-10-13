// @ts-check
// Per-album "Show equipment" flag (albums.show_equipment).
//
// Covers:
//  - new albums show the equipment block by default;
//  - switching the flag off removes the block from the public album page at
//    once (page cache invalidated), in every album page template;
//  - the equipment metadata itself is kept: it is still in the edit form and
//    comes back on the public page when the flag is switched on again.
import { test, expect } from '@playwright/test';
import {
    BASE, createAlbum, uploadCover, deleteAlbum, requireServer, requireAdmin,
} from './_helpers.js';

const CAMERA = `QA Rangefinder ${Date.now()}`;
const EQUIPMENT_HEADING_RE = /<h3[^>]*>\s*(Equipment|Attrezzatura|Matériel)[^<]*<\/h3>/i;

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
async function anonAlbum(browser, slug) {
    const ctx = await browser.newContext();
    try {
        const r = await ctx.request.get(`${BASE}/album/${slug}`);
        return { status: r.status(), body: await r.text() };
    } finally {
        await ctx.close();
    }
}

test.describe.serial('Album equipment visibility', () => {
    let page;
    let album = { id: null, slug: null };
    let base;

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        album = await createAlbum(page, `EQV ${Date.now()}`);
        if (album.id) {
            await uploadCover(page, album.id, 'EQV', '#7c3aed');
            base = await readAlbumForm(page, album.id);
        }
    });

    test.afterAll(async () => {
        try {
            if (album.id) await deleteAlbum(page, album.id);
        } finally {
            await page.close();
        }
    });

    test('EQV-01: a new album has the flag on by default', async () => {
        test.skip(!album.id, 'album fixture unavailable');
        expect(base.show_equipment, 'checkbox checked on a fresh album').toBeDefined();
    });

    for (const template of ['classic', 'hero', 'magazine']) {
        test(`EQV-02 (${template}): block shown when ON, gone when OFF, back when ON again`, async ({ browser }) => {
            test.skip(!album.slug, 'album fixture unavailable');
            const on = { ...base, album_page_template: template, custom_cameras: CAMERA, is_published: 'on', show_equipment: 'on' };
            const off = { ...on };
            delete off.show_equipment;

            await saveAlbum(page, album.id, on);
            let r = await anonAlbum(browser, album.slug);
            expect(r.status).toBe(200);
            expect(r.body).toContain(CAMERA);
            if (template !== 'hero') expect(r.body).toMatch(EQUIPMENT_HEADING_RE);

            await saveAlbum(page, album.id, off);
            r = await anonAlbum(browser, album.slug);
            expect(r.status).toBe(200);
            expect(r.body).not.toMatch(EQUIPMENT_HEADING_RE);
            expect(r.body).not.toContain('fa-camera fa-xs');

            await saveAlbum(page, album.id, on);
            r = await anonAlbum(browser, album.slug);
            expect(r.body).toContain(CAMERA);
        });
    }

    test('EQV-03: hiding the block keeps the metadata and the unchecked state', async () => {
        test.skip(!album.id, 'album fixture unavailable');
        const off = { ...base, custom_cameras: CAMERA, is_published: 'on' };
        delete off.show_equipment;
        await saveAlbum(page, album.id, off);

        const saved = await readAlbumForm(page, album.id);
        expect(saved.show_equipment).toBeUndefined();
        await expect(page.locator('input[name="show_equipment"]')).not.toBeChecked();
        expect(await page.content()).toContain(CAMERA);
    });
});
