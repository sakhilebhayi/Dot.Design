// A node text measurer built from the library's real font metrics (database/templates/Support/font-metrics.json, measured
// from the shipped fonts in headless Chrome), so wrapping in the resize tests matches what the browser does.
import { createNodeMeasurer } from '../../../scripts/dev/qa/metrics.mjs';

export function createAdvanceMeasurer() {
    return createNodeMeasurer();
}
