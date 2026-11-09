// Which address a picture is drawn from. A picture can carry a lighter "editing" copy (`preview_url`, at most about 1600 px wide,
// a few hundred KB instead of the original's megabytes). The canvas and the page strip draw the copy; exports always use the
// original. Above ORIGINAL_ABOVE_ZOOM the canvas draws the original too, so a zoomed-in picture is never soft.
export const ORIGINAL_ABOVE_ZOOM = 1.5;

export const originalUrl = (asset) => (asset ? asset.url || asset.src || null : null);
export const lighterUrl = (asset) => (asset ? asset.preview_url || originalUrl(asset) : null);
export const wantsOriginals = (zoom) => zoom > ORIGINAL_ABOVE_ZOOM;
