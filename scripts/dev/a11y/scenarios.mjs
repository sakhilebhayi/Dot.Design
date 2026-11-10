// Page and state list for the sweep. Each scenario: { id, open(ctx), after?(ctx), close?(ctx), viewports?, settle? }.
// open() leaves the browser on the page in the wanted state; the sweep then runs axe.
const nav = (path) => async ({ c, base }) => { await c.goto(`${base}${path}`, { settle: 300 }); };

/** Wait until the editor has mounted. */
export async function editorReady(c) {
    for (let i = 0; i < 120; i++) {
        if (await c.eval(`document.getElementById('studio-app')?.dataset.ready === '1'`)) return true;
        await c.sleep(100);
    }
    return false;
}

/** Open the editor for a project (debug handle on) and wait for it. */
export async function openEditor({ c, base }, id, { mode = 'simple' } = {}) {
    await c.goto(`${base}/design/projects/${id}`, { settle: 100 });
    await editorReady(c);
    if (mode === 'pro') await uiClick(c, 'Pro mode');
    await c.sleep(300);
}

/** Click a visible button by accessible name or text; on phones, looks inside the More menu. Returns whether it clicked. */
export async function uiClick(c, label, { scope = 'document' } = {}) {
    const find = `(() => {
        const L = ${JSON.stringify(label)}.toLowerCase();
        const vis = (b) => b.offsetParent !== null || getComputedStyle(b).position === 'fixed';
        const hit = [...${scope}.querySelectorAll('button, a, [role=tab], [role=menuitem], [role=menuitemradio]')].find((b) => vis(b) && ((b.getAttribute('aria-label') || '').toLowerCase().startsWith(L) || (b.textContent || '').trim().toLowerCase().startsWith(L)));
        if (hit) { hit.click(); return true; } return false; })()`;
    if (await c.eval(find)) { await c.sleep(250); return true; }
    const more = await c.eval(`(() => { const m = document.querySelector('.st-top__more'); if (m && m.offsetParent) { m.click(); return true; } return false; })()`);
    if (more) { await c.sleep(250); const ok = await c.eval(find); await c.sleep(250); return ok; }
    return false;
}

export const selectIds = (c, ids) => c.eval(`window.__studio.studio.select(${JSON.stringify(ids)})`);
export const pickElement = (c, pred) => c.eval(`(() => { const els = window.__studio.studio.getState().doc.pages[0].elements; const e = els.find(${pred}); if (!e) return null; window.__studio.studio.select([e.id]); return e.id; })()`);

export function scenarios({ fx, readError }) {
    const P = (id, path, extra = {}) => ({ id, open: nav(path), ...extra });
    const G = (id, path, extra = {}) => P(id, path, { guest: true, ...extra });
    const err = (code) => ({
        id: `error-${code}`, guest: true,
        open: async ({ c, base }) => {
            await c.goto(`${base}/cookies`, { settle: 200 });
            await c.eval(`(() => { const html = ${JSON.stringify('')}; })()`);
            const html = readError(code).replace('<head>', `<head><base href="${base}/">`);
            await c.eval(`document.open(); document.write(${JSON.stringify(html)}); document.close();`);
            await c.sleep(900);
        },
    });
    return [
        G('home', '/'),
        G('cookies', '/cookies'),
        G('terms', '/terms-of-service'),
        G('privacy', '/privacy-policy'),
        G('login', '/login'),
        G('register', '/register'),
        G('forgot-password', '/forgot-password'),
        G('reset-password', '/reset-password/abc?email=a@b.test'),
        P('dashboard', '/dashboard'),
        P('start', '/design/new'),
        P('designs-list', '/design/projects'),
        P('templates-grid', '/design/templates'),
        P('brand-list', '/design/brand'),
        P('brand-new', '/design/brand/new'),
        P('brand-edit', `/design/brand/${fx.kit}/edit`),
        P('brand-updates', '/design/brand/updates'),
        P('assets', '/design/assets'),
        P('review-inbox', '/design/review'),
        P('activity', '/design/activity/log'),
        P('settings', '/design/studio-settings'),
        P('integrations', '/design/integrations'),
        G('share-viewer', fx.share?.url ? new URL(fx.share.url).pathname : '/s/none'),
        P('profile', '/user/profile'),
        P('team-create', '/teams/create'),
        P('catalogue-components', '/design-system/components'),
        P('catalogue-tokens', '/design-system/token-sets'),
        err(403), err(404), err(419), err(429), err(500), err(503),
        P('confirm-password', '/user/confirm-password'),
        ...editorScenarios(fx),
    ];
}

function editorScenarios(fx) {
    const E = (id, open, extra = {}) => ({ id: `editor-${id}`, open, settle: 1400, ...extra });
    const rich = (ctx, mode) => openEditor(ctx, fx.rich, { mode });
    const out = [
        E('simple-nothing-selected', (ctx) => rich(ctx, 'simple')),
        E('simple-text-selected', async (ctx) => { await rich(ctx, 'simple'); await pickElement(ctx.c, "(e) => e.type === 'text' && e.name === 'Headline'"); }),
        E('simple-shape-selected', async (ctx) => { await rich(ctx, 'simple'); await pickElement(ctx.c, "(e) => e.type === 'shape' && e.name === 'Button'"); }),
        E('simple-picture-spot-selected', async (ctx) => { await rich(ctx, 'simple'); await pickElement(ctx.c, "(e) => e.type === 'image'"); }),
        E('simple-several-selected', async (ctx) => { await rich(ctx, 'simple'); await selectIds(ctx.c, await ctx.c.eval("window.__studio.studio.getState().doc.pages[0].elements.slice(1,4).map(e=>e.id)")); }),
        E('pro-nothing-selected', (ctx) => rich(ctx, 'pro')),
        E('pro-text-selected', async (ctx) => { await rich(ctx, 'pro'); await pickElement(ctx.c, "(e) => e.type === 'text' && e.name === 'Headline'"); }),
        E('pro-shape-selected', async (ctx) => { await rich(ctx, 'pro'); await pickElement(ctx.c, "(e) => e.type === 'shape' && e.name === 'Button'"); }),
        E('pro-picture-selected', async (ctx) => { await rich(ctx, 'pro'); await pickElement(ctx.c, "(e) => e.type === 'image'"); }),
        E('pro-icon-selected', async (ctx) => { await rich(ctx, 'pro'); await pickElement(ctx.c, "(e) => e.type === 'icon'"); }),
        E('pro-layers', async (ctx) => { await rich(ctx, 'pro'); await uiClick(ctx.c, 'Layers'); }),
        E('multi-page', (ctx) => openEditor(ctx, fx.multi)),
        E('blank-empty-page', (ctx) => openEditor(ctx, fx.blank)),
    ];
    for (const tab of ['templates', 'elements', 'text', 'uploads', 'brand', 'comments', 'animate', 'background', 'versions']) {
        out.push(E(`tab-${tab}`, async (ctx) => { await rich(ctx, 'simple'); await ctx.c.eval(`document.getElementById('st-tab-${tab}')?.click()`); await ctx.c.sleep(500); }, { settle: 900 }));
    }
    out.push(E('tab-text-pro', async (ctx) => { await rich(ctx, 'pro'); await ctx.c.eval(`document.getElementById('st-tab-text')?.click()`); }));
    for (const [name, label] of [['download', 'Download'], ['share', 'Share'], ['other-sizes', 'Other sizes'], ['review', 'Review'], ['shortcuts', 'Keyboard shortcuts'], ['preview', 'Preview']]) {
        out.push(E(`dialog-${name}`, async (ctx) => { await rich(ctx, 'simple'); const ok = await uiClick(ctx.c, label); if (!ok) throw new Error(`no control named ${label}`); }, { settle: 1000, close: async ({ c }) => { await c.key('Escape'); } }));
    }
    out.push(E('context-menu', async (ctx) => { await rich(ctx, 'simple'); await pickElement(ctx.c, "(e) => e.type === 'text'"); await ctx.c.eval(`(() => { const st = document.getElementById('st-stage'); const r = st.getBoundingClientRect(); st.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2, button: 2 })); })()`); }));
    out.push(E('page-menu', async (ctx) => { await rich(ctx, 'simple'); await ctx.c.eval(`document.querySelector('.st-page__menu')?.click()`); }));
    return out;
}
