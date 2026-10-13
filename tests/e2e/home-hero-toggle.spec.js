// @ts-check
// The Classic homepage hero can be switched off from Pages → Home.
//
// Covers:
//  - hero ON  (default): the visible hero block is rendered;
//  - hero OFF: the block is gone, the gallery stays, and the page still has
//    exactly one <h1> (visually hidden) so the document outline survives;
//  - the saved OFF state is reflected back in the admin checkbox;
//  - the toggle is only offered while the Classic template is selected.
import { test, expect } from '@playwright/test';
import { BASE, requireServer, requireAdmin } from './_helpers.js';

const HOME_URL = `${BASE}/admin/pages/home`;
const HERO_BLOCK = 'text-4xl md:text-6xl';

/** Serialize the form as the browser would (unchecked boxes omitted). */
async function readForm(page) {
    await page.goto(HOME_URL, { waitUntil: 'load' });
    return page.$eval('#home-form', (f) => {
        const out = {};
        for (const [k, v] of new FormData(f).entries()) if (typeof v === 'string') out[k] = v;
        return out;
    });
}
async function postForm(page, form) {
    const r = await page.request.post(HOME_URL, { form, maxRedirects: 0, failOnStatusCode: false });
    expect(r.status(), 'POST /admin/pages/home').toBe(302);
}
async function anonHome(browser) {
    const ctx = await browser.newContext();
    try {
        const r = await ctx.request.get(`${BASE}/`);
        return { status: r.status(), body: await r.text() };
    } finally {
        await ctx.close();
    }
}

test.describe.serial('Classic homepage hero toggle', () => {
    let page;
    let orig;

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        orig = await readForm(page);
    });

    test.afterAll(async () => {
        try {
            if (orig) await postForm(page, orig);
        } finally {
            await page.close();
        }
    });

    test('HERO-01: hero is rendered while the toggle is ON', async ({ browser }) => {
        await postForm(page, { ...orig, home_template: 'classic', hero_enabled: '1' });
        const r = await anonHome(browser);
        expect(r.status).toBe(200);
        expect(r.body).toContain(HERO_BLOCK);
        expect(r.body).toContain('id="home-infinite-gallery"');
    });

    test('HERO-02: hero is omitted when the toggle is OFF, gallery and h1 stay', async ({ browser }) => {
        const form = { ...orig, home_template: 'classic' };
        delete form.hero_enabled;
        await postForm(page, form);

        const r = await anonHome(browser);
        expect(r.status).toBe(200);
        expect(r.body).not.toContain(HERO_BLOCK);
        expect(r.body).toContain('id="home-infinite-gallery"');
        expect(r.body.match(/<h1[\s>]/g) ?? []).toHaveLength(1);
        expect(r.body).toMatch(/<h1 class="sr-only">/);
    });

    test('HERO-03: the OFF state is shown unchecked in the admin', async () => {
        await page.goto(HOME_URL, { waitUntil: 'load' });
        await expect(page.locator('input[name="hero_enabled"]')).not.toBeChecked();
    });

    test('HERO-04: the toggle is only offered for the Classic template', async () => {
        await page.goto(HOME_URL, { waitUntil: 'load' });
        const toggle = page.locator('#classic-hero-toggle');
        await expect(toggle).toBeVisible();

        await page.locator('.home-template-option[data-template="editorial"]').click();
        await expect(page.locator('#classic-home-settings')).toBeVisible();
        await expect(toggle).toBeHidden();

        await page.locator('.home-template-option[data-template="classic"]').click();
        await expect(toggle).toBeVisible();
    });
});
