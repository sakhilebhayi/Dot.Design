/*
 * Input: pointer, keyboard, touch and clipboard events -> engine commands.
 *
 * Gestures run inside a history transaction (core.begin ... core.commit), so a
 * whole drag is ONE undo step and live frames skip the heavy checks. Hit testing
 * is geometric. The pointer-move path allocates little and never reads layout
 * (the root rect is cached when a gesture starts).
 *
 * Browser shortcuts are never swallowed: only the studio's own keys are handled
 * (and only when focus is not in a text field), clipboard shortcuts go through
 * the real copy/cut/paste events, and zoom shortcuts (cmd/ctrl + plus, minus, 0)
 * are left alone.
 */
import { angleFromPointer, applyLinear, applyMatrix, invert, makeBounds, rectFromPoints } from '../doc/geometry.js';
import { find } from '../doc/tree.js';
import { cursorFor, framePoints, hitHandle } from './overlay.js';
import { contextList, hitTest, marqueeIds, selectionFrame } from './selection.js';
import { collectTargets, snapEdges, snapMove } from './snap.js';
import { MIN_SIZE, isCorner, moveBoxes, nudge, resizeBox, resizeUnit, rotateUnit, unionBounds } from './transform.js';
import { looksLikeClip } from './clipboard.js';

const DRAG_THRESHOLD_MOUSE = 3;
const DRAG_THRESHOLD_TOUCH = 8;
const LONG_PRESS_MS = 520;
const DOUBLE_TAP_MS = 320;
const SNAP_PX = 6;
const SNAP_PX_TOUCH = 10;

function isEditable(t) {
    if (!t || t.nodeType !== 1) return false;
    const tag = t.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (t.isContentEditable) return true;
    const role = t.getAttribute && t.getAttribute('role');
    return role === 'textbox' || role === 'combobox' || role === 'spinbutton' || role === 'searchbox';
}

export function createInput(deps) {
    const { root, core, viewport } = deps;
    const doc = root.ownerDocument;
    const pointers = new Map();
    const transient = { guides: [], spacing: [], marquee: null, badge: null, hoverId: null, coarse: false };
    const stats = { moves: 0, lastMs: 0, maxMs: 0, totalMs: 0, samples: [] };
    let session = null;
    let pinch = null;
    let spaceDown = false;
    let rect = { left: 0, top: 0 };
    let lastTap = { t: 0, x: 0, y: 0 };
    let longPress = null;
    let pasteStreak = { payload: '', n: 0 };
    let pasteInPlace = false;
    let cursor = '';
    let destroyed = false;

    const state = () => core.getState();
    const prefs = () => deps.prefs();
    const redraw = () => deps.requestDraw();

    function setCursor(c) {
        if (c === cursor) return;
        cursor = c;
        root.style.cursor = c;
    }

    function updateRect() {
        const r = root.getBoundingClientRect();
        rect = { left: r.left, top: r.top };
    }

    function localPoint(e) {
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function resetTransient() {
        transient.guides = [];
        transient.spacing = [];
        transient.marquee = null;
        transient.badge = null;
    }

    // ---- gesture setup -----------------------------------------------------

    function frameNow() {
        const s = state();
        if (!s.selection.length || s.editingId) return null;
        return selectionFrame(core.activePage(s), s.selection);
    }

    function translationOnly(m) {
        return Math.abs(m[0] - 1) < 1e-9 && Math.abs(m[1]) < 1e-9 && Math.abs(m[2]) < 1e-9 && Math.abs(m[3] - 1) < 1e-9;
    }

    function guidesToPage(guides, pm) {
        if (!guides.length || !translationOnly(pm)) return translationOnly(pm) ? guides : [];
        return guides.map((g) => (g.axis === 'x' ? { ...g, pos: g.pos + pm[4], from: g.from + pm[5], to: g.to + pm[5] } : { ...g, pos: g.pos + pm[5], from: g.from + pm[4], to: g.to + pm[4] }));
    }

    function spacingToPage(spacing, pm) {
        if (!spacing.length || !translationOnly(pm)) return translationOnly(pm) ? spacing : [];
        return spacing.map((s) => (s.axis === 'x' ? { ...s, from: s.from + pm[4], to: s.to + pm[4], at: s.at + pm[5] } : { ...s, from: s.from + pm[5], to: s.to + pm[5], at: s.at + pm[4] }));
    }

    function snapTargetsFor(page, frame) {
        const ids = new Set(frame.ids);
        const first = find(page.elements, frame.ids[0]);
        const topLevel = first.ancestors.length === 0;
        const t = collectTargets(page, { excludeIds: ids, elements: first.siblings, includePage: topLevel, width: topLevel ? page.width : first.ancestors[first.ancestors.length - 1].width, height: topLevel ? page.height : first.ancestors[first.ancestors.length - 1].height });
        return t;
    }

    function itemsOf(frame) {
        return frame.elements.map((e) => ({ id: e.id, x: e.x, y: e.y, width: e.width, height: e.height, rotation: e.rotation || 0, type: e.type, fontSize: e.fontSize, autoFit: e.autoFit, flip_x: e.flip_x, flip_y: e.flip_y }));
    }

    function beginMove(s) {
        const page = core.activePage();
        let frame = selectionFrame(page, state().selection);
        if (!frame || frame.allLocked) { session = null; return false; }
        core.begin('element.move');
        if (s.dupe) {
            core.exec('element.duplicate', { ids: state().selection, inPlace: true }, { live: true });
            frame = selectionFrame(core.activePage(), state().selection);
        }
        const items = itemsOf(frame).filter((it) => !find(core.activePage().elements, it.id).element.locked);
        s.items = items;
        s.frame = frame;
        s.union = unionBounds(items);
        s.inv = invert(frame.parentMatrix);
        s.pm = frame.parentMatrix;
        s.targets = snapTargetsFor(core.activePage(), frame);
        s.name = 'element.move';
        return true;
    }

    function beginResize(s) {
        const frame = s.frame;
        core.begin('element.resize');
        s.items = itemsOf(frame);
        s.union = unionBounds(s.items);
        s.inv = invert(frame.parentMatrix);
        s.pm = frame.parentMatrix;
        s.targets = snapTargetsFor(core.activePage(), frame);
        s.name = 'element.resize';
        return true;
    }

    function beginRotate(s) {
        const frame = s.frame;
        core.begin('element.rotate');
        s.items = itemsOf(frame);
        s.inv = invert(frame.parentMatrix);
        s.pm = frame.parentMatrix;
        const u = unionBounds(s.items);
        s.pivot = frame.single ? { x: frame.box.x + frame.box.width / 2, y: frame.box.y + frame.box.height / 2 } : { x: u.cx, y: u.cy };
        const pivotPage = applyMatrix(s.pm, s.pivot.x, s.pivot.y);
        s.pivotPage = pivotPage;
        s.startAngle = angleFromPointer(pivotPage.x, pivotPage.y, s.startPage.x, s.startPage.y, false);
        s.startRotation = frame.single ? frame.box.rotation : 0;
        s.name = 'element.rotate';
        return true;
    }

    // ---- gesture updates ---------------------------------------------------

    function parentDelta(s, e) {
        const z = viewport.zoom;
        const lp = localPoint(e);
        const dx = (lp.x - s.startLocal.x) / z;
        const dy = (lp.y - s.startLocal.y) / z;
        return applyLinear(s.inv, dx, dy);
    }

    function updateMove(s, e) {
        let { x: dx, y: dy } = parentDelta(s, e);
        if (e.shiftKey) { if (Math.abs(dx) > Math.abs(dy)) dy = 0; else dx = 0; }
        const p = prefs();
        resetTransient();
        if (p.snap !== false && !e.altKey) {
            const moving = makeBounds(s.union.left + dx, s.union.top + dy, s.union.right + dx, s.union.bottom + dy);
            const thr = (s.pointerType === 'touch' ? SNAP_PX_TOUCH : SNAP_PX) / viewport.zoom;
            const r = snapMove(moving, s.targets, { threshold: thr, grid: p.grid || 0 });
            if (!(e.shiftKey && Math.abs(dx) < 1e-9)) dx += r.dx;
            if (!(e.shiftKey && Math.abs(dy) < 1e-9)) dy += r.dy;
            transient.guides = guidesToPage(r.guides, s.pm);
            transient.spacing = spacingToPage(r.spacing, s.pm);
        }
        core.exec('element.transform', { boxes: moveBoxes(s.items, dx, dy) }, { live: true });
    }

    function updateResize(s, e) {
        let { x: dx, y: dy } = parentDelta(s, e);
        const frame = s.frame;
        const handle = s.handle;
        const single = frame.single;
        const el = single ? frame.elements[0] : null;
        const isText = !!el && el.type === 'text';
        const corner = isCorner(handle);
        const keepRatio = e.shiftKey || (corner && (isText || (!single && s.items.some((i) => i.type === 'text'))));
        const rot = single ? frame.box.rotation : 0;
        const p = prefs();
        resetTransient();

        if (p.snap !== false && !e.altKey && !rot && !(single && (frame.box.flip_x || frame.box.flip_y)) && !keepRatio && !e.shiftKey) {
            const b = s.union;
            const edges = { x: [], y: [] };
            if (handle.includes('e')) edges.x.push(b.right + dx);
            if (handle.includes('w')) edges.x.push(b.left + dx);
            if (handle.includes('s')) edges.y.push(b.bottom + dy);
            if (handle.includes('n')) edges.y.push(b.top + dy);
            const thr = (s.pointerType === 'touch' ? SNAP_PX_TOUCH : SNAP_PX) / viewport.zoom;
            const r = snapEdges(edges, s.targets, { threshold: thr, span: makeBounds(b.left, b.top, b.right, b.bottom) });
            dx += r.dx; dy += r.dy;
            transient.guides = guidesToPage(r.guides, s.pm);
        }

        const minH = single && el && (el.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow')) ? 1 : MIN_SIZE;
        const opts = { keepRatio, fromCenter: e.altKey, minWidth: MIN_SIZE, minHeight: minH, flipX: single && frame.box.flip_x, flipY: single && frame.box.flip_y };
        let boxes;
        let label;
        if (single) {
            const start = s.items[0];
            const nb = resizeBox({ x: start.x, y: start.y, width: start.width, height: start.height, rotation: start.rotation }, handle, dx, dy, opts);
            const box = { ...nb, rotation: start.rotation };
            if (isText && corner) {
                box.fontSize = Math.max(4, Math.min(2000, Math.round(start.fontSize * (nb.width / start.width) * 100) / 100));
            }
            if (el.type === 'group') box.scaleFont = corner;
            boxes = { [start.id]: box };
            label = `${Math.round(nb.width)} × ${Math.round(nb.height)}`;
        } else {
            const r = resizeUnit(s.items, s.union, handle, dx, dy, { ...opts, scaleFont: corner && keepRatio });
            boxes = r.boxes;
            for (const it of s.items) if (it.type === 'group') boxes[it.id].scaleFont = corner && keepRatio;
            label = `${Math.round(r.bounds.width)} × ${Math.round(r.bounds.height)}`;
        }
        transient.badge = label;
        core.exec('element.transform', { boxes }, { live: true });
    }

    function updateRotate(s, e) {
        const lp = localPoint(e);
        const pagePt = viewport.screenToPage(lp.x, lp.y);
        const now = angleFromPointer(s.pivotPage.x, s.pivotPage.y, pagePt.x, pagePt.y, false);
        let delta = now - s.startAngle;
        resetTransient();
        let boxes;
        if (s.frame.single) {
            let r = s.startRotation + delta;
            if (e.shiftKey) r = Math.round(r / 15) * 15;
            r = ((r + 180) % 360 + 360) % 360 - 180;
            const it = s.items[0];
            boxes = { [it.id]: { x: it.x, y: it.y, width: it.width, height: it.height, rotation: Math.round(r * 100) / 100 } };
            transient.badge = `${Math.round(r)}°`;
        } else {
            if (e.shiftKey) delta = Math.round(delta / 15) * 15;
            boxes = rotateUnit(s.items, s.pivot, delta);
            transient.badge = `${Math.round(delta)}°`;
        }
        core.exec('element.transform', { boxes }, { live: true });
    }

    function updateMarquee(s, e) {
        const lp = localPoint(e);
        const a = s.startPage;
        const b = viewport.screenToPage(lp.x, lp.y);
        transient.marquee = rectFromPoints(a.x, a.y, b.x, b.y);
        const ids = marqueeIds(core.activePage(), a, b, { group: state().group });
        const sel = e.shiftKey || e.metaKey || e.ctrlKey ? Array.from(new Set(s.baseSelection.concat(ids))) : ids;
        core.select(sel);
    }

    // ---- pointer events ----------------------------------------------------

    function onPointerDown(e) {
        if (destroyed || !deps.enabled()) return;
        if (e.target !== root && !root.contains(e.target)) return;
        if (e.target && e.target.closest && e.target.closest('[data-studio-ignore]')) return;
        updateRect();
        root.focus({ preventScroll: true });
        transient.coarse = e.pointerType === 'touch';
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY, type: e.pointerType });
        try { root.setPointerCapture(e.pointerId); } catch (err) { /* capture is best effort */ }

        if (deps.text.active()) deps.text.end(true);

        const touches = Array.from(pointers.values()).filter((p) => p.type === 'touch');
        if (touches.length >= 2) { startPinch(); return; }
        if (session) {
            // a gesture whose pointerup never arrived (focus loss, lost capture) must not block new input
            if (pointers.size > 1) return;
            if (core.inTransaction()) core.cancel();
            session = null;
            resetTransient();
        }

        const lp = localPoint(e);
        const pagePt = viewport.screenToPage(lp.x, lp.y);
        const base = { pointerId: e.pointerId, pointerType: e.pointerType, startLocal: lp, startPage: pagePt, moved: false, button: e.button, shift: e.shiftKey || e.metaKey || e.ctrlKey };

        if (e.button === 1 || (e.button === 0 && (spaceDown || prefs().tool === 'hand'))) {
            session = { ...base, type: 'pan', lastLocal: lp };
            setCursor('grabbing');
            return;
        }
        if (e.button === 2) { selectUnder(pagePt, false); return; }
        if (e.button !== 0 && e.pointerType === 'mouse') return;

        const s = state();
        const page = core.activePage(s);
        const frame = frameNow();

        const handle = frame ? hitHandle(frame, viewport, lp.x, lp.y, e.pointerType === 'touch') : null;
        if (handle) {
            session = { ...base, type: handle.type, handle: handle.handle, frame, pending: true };
            e.preventDefault();
            return;
        }

        let hit = hitTest(page, pagePt.x, pagePt.y, { group: s.group });
        if (!hit && s.group) {
            // clicking outside the entered group leaves it
            const outer = hitTest(page, pagePt.x, pagePt.y, {});
            if (outer) { core.enterGroup(null); hit = outer; } else if (!page.elements.some(() => false)) core.enterGroup(null);
        }
        if (hit) {
            const sel = core.getState().selection;
            if (base.shift) {
                const next = sel.includes(hit.id) ? sel.filter((i) => i !== hit.id) : sel.concat(hit.id);
                core.select(next);
                session = { ...base, type: 'tap-only' };
                return;
            }
            let clickToSelect = null;
            if (!sel.includes(hit.id)) core.select([hit.id]);
            else if (sel.length > 1) clickToSelect = hit.id;
            session = { ...base, type: 'move', pending: true, clickToSelect, dupe: e.altKey };
            startLongPress(e, lp);
            e.preventDefault();
            return;
        }
        // empty space: inside a multi-selection frame still drags the selection
        if (frame && !frame.single && !frame.allLocked) {
            const inv = invert(frame.parentMatrix);
            const lpPoint = applyMatrix(inv, pagePt.x, pagePt.y);
            const b = frame.box;
            if (lpPoint.x >= b.x && lpPoint.x <= b.x + b.width && lpPoint.y >= b.y && lpPoint.y <= b.y + b.height) {
                session = { ...base, type: 'move', pending: true, clickToSelect: null, dupe: e.altKey };
                return;
            }
        }
        if (!base.shift && s.selection.length) core.select([]);
        if (e.pointerType === 'touch') session = { ...base, type: 'pan', lastLocal: lp, pending: true };
        else session = { ...base, type: 'marquee', pending: true, baseSelection: base.shift ? s.selection : [] };
    }

    function selectUnder(pagePt) {
        const s = state();
        const hit = hitTest(core.activePage(s), pagePt.x, pagePt.y, { group: s.group });
        if (hit && !s.selection.includes(hit.id)) core.select([hit.id]);
    }

    function startLongPress(e, lp) {
        if (e.pointerType !== 'touch') return;
        clearTimeout(longPress);
        longPress = setTimeout(() => {
            if (!session || session.moved) return;
            const target = session;
            session = null;
            pointers.clear();
            deps.emit('contextmenu', { x: target.startLocal.x + rect.left, y: target.startLocal.y + rect.top, ids: state().selection.slice(), source: 'long-press' });
        }, LONG_PRESS_MS);
        void lp;
    }

    function startPinch() {
        if (session && core.inTransaction()) core.cancel();
        session = null;
        clearTimeout(longPress);
        resetTransient();
        const pts = Array.from(pointers.values()).filter((p) => p.type === 'touch').slice(0, 2);
        const mid = { x: (pts[0].x + pts[1].x) / 2 - rect.left, y: (pts[0].y + pts[1].y) / 2 - rect.top };
        pinch = { startView: { ...viewport.view }, startMid: mid, startDist: Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y) };
        redraw();
    }

    function onPointerMove(e) {
        if (destroyed) return;
        const t0 = performance.now();
        const p = pointers.get(e.pointerId);
        if (p) { p.x = e.clientX; p.y = e.clientY; }

        if (pinch) {
            const pts = Array.from(pointers.values()).filter((q) => q.type === 'touch').slice(0, 2);
            if (pts.length === 2) {
                const mid = { x: (pts[0].x + pts[1].x) / 2 - rect.left, y: (pts[0].y + pts[1].y) / 2 - rect.top };
                const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
                deps.pinchTo(pinch, mid, dist);
            }
            return;
        }

        const s = session;
        if (!s || s.pointerId !== e.pointerId) {
            if (!s && e.pointerType !== 'touch') updateHover(e);
            return;
        }
        const lp = localPoint(e);

        if (s.type === 'pan') {
            if (s.pending && !s.moved) {
                if (Math.hypot(lp.x - s.startLocal.x, lp.y - s.startLocal.y) < DRAG_THRESHOLD_TOUCH) return;
                s.moved = true;
            }
            viewport.panBy(lp.x - s.lastLocal.x, lp.y - s.lastLocal.y);
            s.lastLocal = lp;
            return;
        }
        if (s.type === 'tap-only') return;

        if (!s.moved) {
            const th = s.pointerType === 'touch' ? DRAG_THRESHOLD_TOUCH : DRAG_THRESHOLD_MOUSE;
            if (Math.hypot(lp.x - s.startLocal.x, lp.y - s.startLocal.y) < th) return;
            s.moved = true;
            clearTimeout(longPress);
            let ok = true;
            if (s.type === 'move') ok = beginMove(s);
            else if (s.type === 'resize') ok = beginResize(s);
            else if (s.type === 'rotate') ok = beginRotate(s);
            if (!ok) { session = null; return; }
            setCursor(s.type === 'move' ? 'move' : s.type === 'rotate' ? 'grabbing' : cursor);
        }
        if (s.type === 'move') updateMove(s, e);
        else if (s.type === 'resize') updateResize(s, e);
        else if (s.type === 'rotate') updateRotate(s, e);
        else if (s.type === 'marquee') updateMarquee(s, e);
        redraw();

        const dt = performance.now() - t0;
        stats.moves += 1;
        stats.lastMs = dt;
        stats.totalMs += dt;
        if (dt > stats.maxMs) stats.maxMs = dt;
        if (stats.samples.length < 400) stats.samples.push(dt);
    }

    function onPointerUp(e) {
        if (destroyed) return;
        const had = pointers.has(e.pointerId);
        pointers.delete(e.pointerId);
        try { root.releasePointerCapture(e.pointerId); } catch (err) { /* already released */ }
        clearTimeout(longPress);
        if (pinch) {
            if (Array.from(pointers.values()).filter((p) => p.type === 'touch').length < 2) { pinch = null; session = { type: 'ignore', pointerId: -1 }; if (!pointers.size) session = null; }
            return;
        }
        if (session && session.type === 'ignore' && !pointers.size) { session = null; return; }
        const s = session;
        if (!s || s.pointerId !== e.pointerId || !had) return;
        session = null;
        const wasMoved = s.moved;

        if (s.type === 'move' || s.type === 'resize' || s.type === 'rotate') {
            if (wasMoved) {
                const r = core.commit({ name: s.name });
                if (!r.ok && r.error) deps.emit('error', r.error);
            } else if (s.type === 'move' && s.clickToSelect) core.select([s.clickToSelect]);
        }
        resetTransient();
        setCursor(spaceDown ? 'grab' : '');
        redraw();

        if (!wasMoved && s.type !== 'pan' && e.button === 0 || (!wasMoved && s.type === 'pan' && s.pending)) {
            if (e.pointerType === 'touch') {
                const now = performance.now();
                if (now - lastTap.t < DOUBLE_TAP_MS && Math.hypot(e.clientX - lastTap.x, e.clientY - lastTap.y) < 24) { lastTap = { t: 0, x: 0, y: 0 }; activate(localPoint(e)); } else lastTap = { t: now, x: e.clientX, y: e.clientY };
            }
        }
    }

    function onPointerCancel(e) {
        pointers.delete(e.pointerId);
        clearTimeout(longPress);
        if (pinch && Array.from(pointers.values()).filter((p) => p.type === 'touch').length < 2) pinch = null;
        if (session && session.pointerId === e.pointerId) {
            if (core.inTransaction()) core.cancel();
            session = null;
            resetTransient();
            redraw();
        }
    }

    function updateHover(e) {
        const lp = localPoint(e);
        const s = state();
        let hoverId = null;
        let c = spaceDown ? 'grab' : '';
        if (!s.editingId) {
            const frame = frameNow();
            const h = frame ? hitHandle(frame, viewport, lp.x, lp.y, false) : null;
            if (h) c = h.type === 'rotate' ? 'crosshair' : cursorFor(h.handle, frame.single ? frame.box.rotation : 0);
            else if (!spaceDown) {
                const pt = viewport.screenToPage(lp.x, lp.y);
                const hit = hitTest(core.activePage(s), pt.x, pt.y, { group: s.group });
                if (hit) { hoverId = s.selection.includes(hit.id) ? null : hit.id; c = 'move'; }
            }
        }
        setCursor(c);
        if (hoverId !== transient.hoverId) { transient.hoverId = hoverId; redraw(); }
    }

    function onPointerLeave() {
        if (transient.hoverId !== null) { transient.hoverId = null; redraw(); }
    }

    function activate(lp) {
        const s = state();
        const page = core.activePage(s);
        const pt = viewport.screenToPage(lp.x, lp.y);
        const hit = hitTest(page, pt.x, pt.y, { group: s.group });
        if (!hit) return;
        const el = find(page.elements, hit.id).element;
        if (el.type === 'text') { core.select([el.id]); deps.text.start(el.id); return; }
        if (el.type === 'group') {
            core.enterGroup(el.id);
            const inner = hitTest(core.activePage(), pt.x, pt.y, { group: el.id });
            if (inner) core.select([inner.id]);
            return;
        }
        deps.emit('activate', { id: el.id, type: el.type });
    }

    function onDblClick(e) {
        if (destroyed || !deps.enabled() || e.pointerType === 'touch') return;
        if (deps.text.active()) return;
        updateRect();
        activate(localPoint(e));
    }

    function onContextMenu(e) {
        if (destroyed || !deps.enabled()) return;
        if (e.target && e.target.closest && e.target.closest('[data-studio-ignore]')) return;
        e.preventDefault();
        updateRect();
        const lp = localPoint(e);
        selectUnder(viewport.screenToPage(lp.x, lp.y));
        deps.emit('contextmenu', { x: e.clientX, y: e.clientY, ids: state().selection.slice(), source: 'pointer' });
    }

    function onWheel(e) {
        if (destroyed || !deps.enabled()) return;
        if (e.target && e.target.closest && e.target.closest('[data-studio-ignore]')) return;
        e.preventDefault();
        updateRect();
        const lp = localPoint(e);
        viewport.wheel(e, prefs().wheel || 'scroll', lp);
    }

    // Safari trackpad pinch
    let gestureStart = null;
    function onGestureStart(e) { e.preventDefault(); updateRect(); gestureStart = { ...viewport.view }; }
    function onGestureChange(e) {
        if (!gestureStart) return;
        e.preventDefault();
        const lp = localPoint(e);
        viewport.setView({ zoom: gestureStart.zoom, x: gestureStart.x, y: gestureStart.y });
        viewport.setZoom(gestureStart.zoom * e.scale, lp);
    }
    function onGestureEnd() { gestureStart = null; }

    // ---- keyboard ----------------------------------------------------------

    function onKeyDown(e) {
        if (destroyed || !deps.enabled() || e.defaultPrevented) return;
        const t = e.target;
        if (isEditable(t) || deps.text.active()) return;
        const mod = e.metaKey || e.ctrlKey;
        const key = e.key;
        const s = state();

        if (key === ' ' && !mod && !e.altKey) {
            if (!spaceDown) { spaceDown = true; setCursor('grab'); }
            if (t === root || t === doc.body) e.preventDefault();
            return;
        }

        if (mod) {
            const k = key.toLowerCase();
            if (k === 'z' && !e.altKey) { e.preventDefault(); if (e.shiftKey) core.redo(); else core.undo(); return; }
            if (k === 'y' && !e.shiftKey) { e.preventDefault(); core.redo(); return; }
            if (k === 'v' && e.shiftKey) pasteInPlace = true;
            if (!s.selection.length && k !== 'a') return;
            if (k === 'd') { e.preventDefault(); deps.exec('element.duplicate', { ids: s.selection }); return; }
            if (k === 'g') { e.preventDefault(); deps.exec(e.shiftKey ? 'element.ungroup' : 'element.group', { ids: s.selection }); return; }
            if (k === 'a') { e.preventDefault(); core.selectAll(); return; }
            if (key === '[') { e.preventDefault(); deps.exec('element.reorder', { ids: s.selection, to: 'back-most' }); return; }
            if (key === ']') { e.preventDefault(); deps.exec('element.reorder', { ids: s.selection, to: 'front' }); return; }
            return; // everything else (zoom, reload, new tab, copy/cut/paste events …) belongs to the browser
        }

        if (key === 'Escape') {
            if (session && core.inTransaction()) { core.cancel(); session = null; resetTransient(); redraw(); return; }
            if (s.group) { core.enterGroup(null); return; }
            if (s.selection.length) core.select([]);
            return;
        }
        if (key === 'Delete' || key === 'Backspace') {
            if (s.selection.length) { e.preventDefault(); deps.exec('element.remove', { ids: s.selection }); }
            return;
        }
        if (key.startsWith('Arrow')) {
            if (!s.selection.length) return;
            e.preventDefault();
            const d = nudge(key, e.shiftKey);
            deps.exec('element.move', { ids: s.selection, dx: d.dx, dy: d.dy }, { key: 'nudge:' + s.selection.join(',') });
            return;
        }
        if (key === 'Enter') {
            if (s.selection.length === 1) {
              const el = find(core.activePage(s).elements, s.selection[0]).element;
              if (el.type === 'text') { e.preventDefault(); deps.text.start(el.id, { selectAll: true }); } else if (el.type === 'group') { e.preventDefault(); core.enterGroup(el.id); if (el.children.length) core.select([el.children[el.children.length - 1].id]); }
            }
            return;
        }
        if (key === '[' || key === ']') {
            if (s.selection.length) { e.preventDefault(); deps.exec('element.reorder', { ids: s.selection, to: key === ']' ? 'forward' : 'back' }); }
            return;
        }
        if (key === 'Tab' && t === root) {
            const list = contextList(core.activePage(s), s.group).list.filter((x) => !x.hidden && !x.locked);
            if (!list.length) return;
            const cur = s.selection.length ? list.findIndex((x) => x.id === s.selection[s.selection.length - 1]) : -1;
            const next = cur + (e.shiftKey ? -1 : 1);
            if (next >= 0 && next < list.length) { e.preventDefault(); core.select([list[next].id]); } else if (cur !== -1) core.select([]);
        }
    }

    function onKeyUp(e) {
        if (e.key === ' ' && spaceDown) { spaceDown = false; setCursor(''); }
        if (e.key.toLowerCase() === 'v') pasteInPlace = false;
    }

    function onBlur() {
        spaceDown = false;
        setCursor('');
    }

    // ---- clipboard events --------------------------------------------------

    function clipTargetOk(e) {
        return !destroyed && deps.enabled() && !isEditable(e.target) && !deps.text.active();
    }

    function onCopy(e) {
        if (!clipTargetOk(e) || !state().selection.length) return;
        const text = deps.clipboard.serializeSelection();
        if (!text) return;
        e.clipboardData.setData('text/plain', text);
        e.preventDefault();
        deps.clipboard.remember(text);
    }

    function onCut(e) {
        if (!clipTargetOk(e) || !state().selection.length) return;
        const text = deps.clipboard.serializeSelection();
        if (!text) return;
        e.clipboardData.setData('text/plain', text);
        e.preventDefault();
        deps.clipboard.remember(text);
        deps.exec('element.remove', { ids: state().selection });
    }

    function onPaste(e) {
        if (!clipTargetOk(e)) return;
        const cd = e.clipboardData;
        if (!cd) return;
        const files = Array.from(cd.files || []).filter((f) => /^image\//.test(f.type));
        const text = cd.getData('text/plain') || '';
        e.preventDefault();
        if (looksLikeClip(text)) {
            pasteStreak = pasteStreak.payload === text ? { payload: text, n: pasteStreak.n + 1 } : { payload: text, n: 1 };
            deps.clipboard.pastePayload(text, { streak: pasteStreak.n, inPlace: pasteInPlace });
            pasteInPlace = false;
        } else if (files.length) deps.emit('paste-files', { files });
        else if (text.trim()) deps.clipboard.pasteText(text);
    }

    // ---- wiring ------------------------------------------------------------

    root.addEventListener('pointerdown', onPointerDown);
    root.addEventListener('pointermove', onPointerMove);
    root.addEventListener('pointerup', onPointerUp);
    root.addEventListener('pointercancel', onPointerCancel);
    root.addEventListener('pointerleave', onPointerLeave);
    root.addEventListener('dblclick', onDblClick);
    root.addEventListener('contextmenu', onContextMenu);
    root.addEventListener('wheel', onWheel, { passive: false });
    root.addEventListener('gesturestart', onGestureStart);
    root.addEventListener('gesturechange', onGestureChange);
    root.addEventListener('gestureend', onGestureEnd);
    root.addEventListener('blur', onBlur);
    doc.addEventListener('keydown', onKeyDown);
    doc.addEventListener('keyup', onKeyUp);
    doc.addEventListener('copy', onCopy);
    doc.addEventListener('cut', onCut);
    doc.addEventListener('paste', onPaste);

    return {
        transient,
        stats,
        resetStats() { stats.moves = 0; stats.lastMs = 0; stats.maxMs = 0; stats.totalMs = 0; stats.samples = []; },
        get spaceDown() { return spaceDown; },
        cancelGesture() {
            if (session && core.inTransaction()) core.cancel();
            session = null; pinch = null; pointers.clear();
            resetTransient();
            redraw();
        },
        destroy() {
            destroyed = true;
            clearTimeout(longPress);
            root.removeEventListener('pointerdown', onPointerDown);
            root.removeEventListener('pointermove', onPointerMove);
            root.removeEventListener('pointerup', onPointerUp);
            root.removeEventListener('pointercancel', onPointerCancel);
            root.removeEventListener('pointerleave', onPointerLeave);
            root.removeEventListener('dblclick', onDblClick);
            root.removeEventListener('contextmenu', onContextMenu);
            root.removeEventListener('wheel', onWheel);
            root.removeEventListener('gesturestart', onGestureStart);
            root.removeEventListener('gesturechange', onGestureChange);
            root.removeEventListener('gestureend', onGestureEnd);
            root.removeEventListener('blur', onBlur);
            doc.removeEventListener('keydown', onKeyDown);
            doc.removeEventListener('keyup', onKeyUp);
            doc.removeEventListener('copy', onCopy);
            doc.removeEventListener('cut', onCut);
            doc.removeEventListener('paste', onPaste);
        },
    };
}

export { framePoints };
