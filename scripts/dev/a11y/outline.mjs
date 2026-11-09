// R-45 a11y: what an assistive technology is told. Prints the landmark and heading outline of a page from Chrome's own
// accessibility tree (the same tree VoiceOver, NVDA and TalkBack read), plus how many controls have no accessible name.
//   node scripts/dev/a11y/outline.mjs /design/projects/1
import { launch, login } from './lib.mjs';
import { editorReady } from './scenarios.mjs';

const path = process.argv[2] || '/design/projects/1';
const base = process.env.BASE || 'http://127.0.0.1:8490';
const c = await launch();
try {
    await login(c, base);
    await c.viewport(1440, 900);
    await c.goto(`${base}${path}`, { settle: 300 });
    if (path.startsWith('/design/projects/')) await editorReady(c);
    await c.sleep(600);
    const nodes = await c.axTree();
    const byId = new Map(nodes.map((n) => [n.nodeId, n]));
    const LAND = new Set(['banner', 'navigation', 'main', 'complementary', 'contentinfo', 'region', 'search', 'form', 'dialog']);
    const out = [];
    const walk = (n, depth) => {
        if (!n || n.ignored) { for (const id of (n?.childIds || [])) walk(byId.get(id), depth); return; }
        const role = n.role?.value; const name = (n.name?.value || '').replace(/\s+/g, ' ').slice(0, 60);
        const level = n.properties?.find((p) => p.name === 'level')?.value?.value;
        if (LAND.has(role) && (role !== 'region' || name)) out.push(`${'  '.repeat(depth)}${role}${name ? ` "${name}"` : ''}`);
        else if (role === 'heading') out.push(`${'  '.repeat(depth)}h${level} "${name}"`);
        for (const id of (n.childIds || [])) walk(byId.get(id), LAND.has(role) ? depth + 1 : depth);
    };
    walk(nodes[0], 0);
    console.log(out.join('\n'));
    const controls = nodes.filter((n) => !n.ignored && ['button', 'link', 'textbox', 'combobox', 'checkbox', 'radio', 'slider', 'tab', 'menuitem', 'switch', 'spinbutton'].includes(n.role?.value));
    const unnamed = controls.filter((n) => !(n.name?.value || '').trim());
    console.log(`\n${controls.length} controls in the accessibility tree, ${unnamed.length} without a name`);
    for (const n of unnamed.slice(0, 5)) console.log('  unnamed:', n.role.value);
} finally { c.close(); }
