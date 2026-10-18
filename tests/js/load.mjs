// The editor's logic lives in plain classic scripts under public/js/design-editor
// (no build step, so they load before Livewire starts Alpine). This runs one the
// way a browser would -- as a script that registers itself on `window` -- and
// hands back what it registered on window.DotDesign. It runs in this realm (not
// a vm context) so results compare cleanly with assert.deepEqual.
import fs from 'node:fs';

export function load(name) {
    const window = {};
    const source = fs.readFileSync(new URL(`../../public/js/design-editor/${name}.js`, import.meta.url), 'utf8');
    new Function('window', source).call(window, window);
    return window.DotDesign;
}
