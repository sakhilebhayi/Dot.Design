// Reusable UI flows (the way a person does them).
import { openEditor } from './harness.mjs';

/** The guided brief for "Announce a sale", answered and finished with a design whose size label matches `sizeText`. Returns the project id. */
export async function designFromBrief(page, { what = 'Winter pie special', business, phone, logoPath = null, useKit = false, style = 'Plenty of space, simple and easy to read', size = 'Link-style or image post', cardMatch = /Facebook post/ }) {
    await page.goto('/design/new/brief?goal=announce_sale');
    const next = () => page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('textbox').first().fill(what); await next();
    await page.locator('input[type=text]').first().fill(business); await next();
    if (phone) await page.getByLabel(/phone/i).first().fill(phone);
    await next();
    if (logoPath) {
        await page.locator('input[type=file]').first().setInputFiles(logoPath);
        await page.getByText(/was added and selected|was already in your uploads/).waitFor();
        if (useKit) await page.getByRole('radio', { name: /Use my brand kit/ }).check({ force: true });
    }
    await next();
    await page.locator('button', { hasText: style }).click(); await next();
    await page.locator('button', { hasText: size }).click();
    await page.getByRole('button', { name: 'See starting points' }).click();
    await page.locator('button', { hasText: 'Use this design' }).first().waitFor({ timeout: 20000 });
    let target = null;
    for (const b of await page.locator('button', { hasText: 'Use this design' }).all()) {
        if (cardMatch.test(await b.evaluate((el) => el.parentElement.innerText + el.parentElement.parentElement.innerText))) { target = b; break; }
    }
    if (!target) throw new Error('no design card matched');
    await target.click();
    await page.waitForURL(/\/design\/projects\/\d+$/, { timeout: 30000 });
    return Number(page.url().match(/projects\/(\d+)/)[1]);
}

/** Opens the Share dialog and returns once it is visible. */
export const openShare = async (page) => { await page.getByRole('button', { name: 'Share', exact: true }).click(); await page.locator('dialog[open]').waitFor(); };
