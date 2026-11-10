/*
 * Export presets in plain language, chosen by the project's format kind. Pure. Simple mode shows these as one-click
 * buttons; Pro opens "Advanced" with every option. Each preset is a complete `settings` object for runExport().
 */

export const PLATFORM_LABELS = {
    instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', x: 'X', tiktok: 'TikTok', youtube: 'YouTube', whatsapp: 'WhatsApp', pinterest: 'Pinterest',
};

const clone = (o) => JSON.parse(JSON.stringify(o));

export const DEFAULT_SETTINGS = Object.freeze({
    format: 'png', pages: [0], sizes: [{ mode: 'scale', scale: 1 }], quality: 0.92, transparent: false, embedFonts: true,
    pdfTarget: { mode: 'dpi', dpi: 150 }, fps: 15, maxEdge: 480, pdfMode: 'vector', bleedMm: 0, marks: false,
});

/** 'print' for paper formats (mm or in, or a print format in the catalog), otherwise 'digital'. */
export function kindOf(project, format) {
    if (format && (format.kind === 'print' || format.kind === 'digital')) return format.kind;
    return project && (project.unit === 'mm' || project.unit === 'in') ? 'print' : 'digital';
}

export function platformOf(format) {
    return format && PLATFORM_LABELS[format.platform] ? PLATFORM_LABELS[format.platform] : null;
}

/**
 * quickActions({project, format, pageCount, animated, webmSupported}) -> [{id, label, hint, settings, primary?}]
 * Wording is plain: "Download for Instagram", "Download for printing", "Download as PDF".
 */
export function quickActions({ project, format, animated = false, webmSupported = false }) {
    const kind = kindOf(project, format);
    const platform = platformOf(format);
    const out = [];
    const base = clone(DEFAULT_SETTINGS);
    if (kind === 'print') {
        out.push({ id: 'print', label: 'Download for printing', hint: 'PDF, sharp enough for a print shop (300 dpi)', primary: true, settings: { ...base, format: 'pdf', pdfTarget: { mode: 'dpi', dpi: 300 } } });
        out.push({ id: 'share', label: 'Download as a picture', hint: 'PNG, good for email and chat', settings: { ...base, format: 'png', sizes: [{ mode: 'dpi', dpi: 150 }] } });
        out.push({ id: 'pdf', label: 'Download as PDF', hint: 'Smaller file for screens (150 dpi)', settings: { ...base, format: 'pdf', pdfTarget: { mode: 'dpi', dpi: 150 } } });
    } else {
        out.push({ id: 'screen', label: platform ? `Download for ${platform}` : 'Download for screens', hint: 'PNG at the exact size', primary: true, settings: { ...base, format: 'png', sizes: [{ mode: 'scale', scale: 1 }] } });
        out.push({ id: 'small', label: 'Download a smaller file', hint: 'JPG, quick to send', settings: { ...base, format: 'jpg', quality: 0.85, sizes: [{ mode: 'scale', scale: 1 }] } });
        out.push({ id: 'pdf', label: 'Download as PDF', hint: 'One PDF of your pages', settings: { ...base, format: 'pdf', pdfTarget: { mode: 'scale', scale: 2 } } });
    }
    if (animated) {
        out.push({ id: 'gif', label: 'Download as animated GIF', hint: 'Plays everywhere, larger files', settings: { ...base, format: 'gif', fps: 15, maxEdge: 480 } });
        if (webmSupported) out.push({ id: 'video', label: 'Download as video', hint: 'WebM, smooth and small', settings: { ...base, format: 'webm', fps: 30, maxEdge: 720 } });
    }
    return out;
}

export const FORMAT_TABS = [
    { id: 'png', label: 'PNG', help: 'Sharp pictures. Can be see-through.' },
    { id: 'jpg', label: 'JPG', help: 'Smaller files for photos and sharing.' },
    { id: 'pdf', label: 'PDF', help: 'For printing and sending pages.' },
    { id: 'svg', label: 'SVG', help: 'Editable shapes and text, for designers.' },
    { id: 'gif', label: 'GIF', help: 'Animated picture that plays everywhere.', animatedOnly: true },
    { id: 'webm', label: 'Video', help: 'WebM video, smooth and small.', animatedOnly: true },
];

/** The tabs that make sense for this design (animated formats only when something is animated). */
export function tabsFor({ animated }) {
    return FORMAT_TABS.filter((t) => animated || !t.animatedOnly);
}

export const SCALE_CHOICES = [1, 2, 3, 4];
export const DPI_CHOICES = [150, 300, 600];
export const FPS_GIF = [10, 15, 20];
export const FPS_VIDEO = [24, 30];
export const EDGE_CHOICES = [{ id: 480, label: 'Small' }, { id: 720, label: 'Medium' }, { id: 1080, label: 'Large' }];

/** Targets from the dialog state, as resolveTarget() specs. */
export function targetsFromState(state, kind) {
    const out = [];
    if (kind === 'print' && state.mode === 'dpi') for (const dpi of DPI_CHOICES) if (state.dpis.has(dpi)) out.push({ mode: 'dpi', dpi });
    if (state.mode !== 'dpi') for (const s of SCALE_CHOICES) if (state.scales.has(s)) out.push({ mode: 'scale', scale: s });
    const w = parseInt(state.customWidth, 10);
    if (state.useWidth && Number.isFinite(w) && w >= 16 && w <= 20000) out.push({ mode: 'width', width: w });
    return out;
}

export function describeTarget(page, spec) {
    const k = spec.mode === 'scale' ? spec.scale : spec.mode === 'dpi' ? spec.dpi / 72 : spec.width / page.width;
    return { width: Math.round(page.width * k), height: spec.mode === 'width' ? Math.round((spec.width * page.height) / page.width) : Math.round(page.height * k) };
}

export function sizeLabel(spec) {
    if (spec.mode === 'scale') return `${spec.scale}x`;
    if (spec.mode === 'dpi') return `${spec.dpi} dpi`;
    return `${spec.width} px wide`;
}

/** Plain-language warning for a size (or '' when fine). */
export function sizeWarning(width, height, format) {
    const px = width * height;
    if (px > 50e6) return 'This is a very large picture. Some browsers cannot make it; it will be made smaller if needed.';
    if (format === 'gif' && px > 2e6) return 'Large GIFs get big quickly. Choose a smaller size to keep the file light.';
    return '';
}
