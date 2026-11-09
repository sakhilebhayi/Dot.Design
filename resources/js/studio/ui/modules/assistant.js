/*
 * Assistant module (docs/R45_UI_CONTRACT.md): the "Ask for help" (Simple) / "Assistant" (Pro) left tab.
 * Quick fixes work without AI. Asking the assistant and writing text need the AI flag and are honest when it is off.
 * The panel logic lives in ../../assist/ so it can be tested without the shell.
 */
import '../../../../css/studio-assistant.css';
import { createCanvasMeasurer, layoutText } from '../../engine/text-layout.js';
import { createController } from '../../assist/controller.js';
import { mountPanel } from '../../assist/panel.js';

/** A text measurer for the helpers: real glyph widths from the editor's loaded fonts, or null when they are not available. */
function makeMeasurers(studio) {
    const fonts = studio.fonts;
    if (!fonts || typeof document === 'undefined') return { measurer: undefined, measure: null };
    const measurer = createCanvasMeasurer({ stackFor: fonts.stackFor, resolveWeight: fonts.resolveWeight });
    const measure = (el) => {
        const r = layoutText({ ...el, autoFit: 'none' }, measurer);
        return { contentHeight: r.contentHeight, overflowWidth: r.lines.some((l) => l.width > el.width + 0.5) };
    };
    return { measurer, measure };
}

function mountAssistant(el, ctx) {
    const { measurer, measure } = makeMeasurers(ctx.studio);
    const controller = createController(ctx, { measure });
    const panel = mountPanel(el, ctx, controller, { measurer, formats: ctx.config.formats || [] });
    return { destroy: () => panel.destroy() };
}

export function register(ctx) {
    // Plain words in Simple mode, the product word in Pro mode. One panel behind both.
    ctx.registerLeftTab({ id: 'assistant-simple', label: 'Ask for help', icon: 'sparkles', order: 80, modes: ['simple'], mount: mountAssistant });
    ctx.registerLeftTab({ id: 'assistant', label: 'Assistant', icon: 'sparkles', order: 80, modes: ['pro'], mount: mountAssistant });
}
