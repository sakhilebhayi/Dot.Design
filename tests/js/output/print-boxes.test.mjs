import test from 'node:test';
import assert from 'node:assert/strict';
import { applyPrintBoxes, cropMarksSvg, edgeMessage, elementsOnEdge, hasPrintBoxes, mmToUnits, padUnits, BLEED_CHOICES, COLOUR_NOTE } from '../../../resources/js/studio/output/print-boxes.js';
import { renderPageToSvgString } from '../../../resources/js/studio/engine/render.js';
import { createStubMeasurer } from '../../../resources/js/studio/engine/text-layout.js';
import { mkPage, rect } from '../helpers.mjs';

const svgOf = (elements, extra = {}) => renderPageToSvgString(mkPage(elements, { width: 595, height: 842, ...extra }), { measure: createStubMeasurer(), forExport: true });

test('units: 3 mm is 8.504 page units (print pages are 72 per inch)', () => {
    assert.ok(Math.abs(mmToUnits(3) - 8.5039) < 1e-3);
    assert.deepEqual(BLEED_CHOICES, [0, 3, 5]);
    assert.equal(padUnits({ bleedMm: 0, marks: false }), 0);
    assert.ok(Math.abs(padUnits({ bleedMm: 3, marks: false }) - mmToUnits(3)) < 1e-9);
    assert.ok(Math.abs(padUnits({ bleedMm: 3, marks: true }) - mmToUnits(3 + 1 + 5)) < 1e-9, 'marks start 1 mm outside the bleed and run 5 mm');
    assert.equal(hasPrintBoxes({ bleedMm: 0, marks: false }), false);
    assert.equal(hasPrintBoxes({ bleedMm: 3 }), true);
    assert.equal(hasPrintBoxes({ marks: true }), true);
});

test('applyPrintBoxes: bigger viewBox, background extended into the bleed, content clipped to the bleed box, marks outside it', () => {
    const base = svgOf([rect({ id: 'a', x: 10, y: 10, width: 50, height: 50 })], { background: { type: 'solid', color: '#ffeecc' } });
    const out = applyPrintBoxes(base, { width: 595, height: 842, bleedMm: 3, marks: true });
    assert.equal(out.changed, true);
    const pad = padUnits({ bleedMm: 3, marks: true });
    assert.ok(Math.abs(out.width - (595 + 2 * pad)) < 1e-6);
    assert.match(out.svg, new RegExp(`viewBox="-${(Math.round(pad * 1000) / 1000)} -${(Math.round(pad * 1000) / 1000)} `));
    assert.match(out.svg, /<rect x="-8\.504" y="-8\.504" width="612\.008" height="859\.008" fill="#ffeecc" data-bg="1"\/>/);
    assert.match(out.svg, /<clipPath id="dd-bleedclip"><rect x="-8\.504"/);
    assert.match(out.svg, /data-crop-marks="1"/);
    assert.equal((cropMarksSvg({ width: 595, height: 842, bleedMm: 3 }).match(/[MVH]/g) || []).length, 8 * 2, 'eight marks, each a move and a line');
    // no bleed and no marks leaves the drawing alone apart from the frame
    const none = applyPrintBoxes(base, { width: 595, height: 842, bleedMm: 0, marks: false });
    assert.match(none.svg, /viewBox="-0 -0 595 842"|viewBox="0 0 595 842"/);
});

test('crop marks stay outside the bleed: the nearest mark starts 1 mm beyond it', () => {
    const marks = cropMarksSvg({ width: 100, height: 100, bleedMm: 3 });
    const gap = mmToUnits(3) + mmToUnits(1);
    assert.match(marks, new RegExp(`M0 -${Math.round(gap * 1000) / 1000}V`));
});

test('elements that stop exactly at the page edge are named, so the person can extend them into the bleed', () => {
    const page = mkPage([
        rect({ id: 'photo', x: 0, y: 0, width: 595, height: 300 }),
        rect({ id: 'inside', x: 40, y: 400, width: 100, height: 100 }),
        rect({ id: 'over', x: -9, y: 600, width: 620, height: 100 }),
    ], { width: 595, height: 842 });
    assert.deepEqual(elementsOnEdge(page).map((e) => e.id), ['photo']);
    assert.match(edgeMessage(page, 3), /One element ends exactly at the page edge, so it does not continue into the 3 mm bleed\./);
    assert.equal(edgeMessage(page, 0), '');
    assert.match(COLOUR_NOTE, /Exports are RGB\. CMYK conversion is not provided/);
});
