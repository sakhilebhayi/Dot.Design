/*
 * Alpine component for the canvas editor (resources/views/design/projects/show.blade.php).
 * Loaded as a classic script before Livewire starts Alpine, after geometry.js
 * and history.js, whose pure functions do the maths and bookkeeping. This file
 * is the glue between them, the DOM and the server, and is verified in a
 * browser rather than unit tests.
 */
(function (root) {
    'use strict';

    var G = root.DotDesign.geometry;
    var H = root.DotDesign.history;

    var HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
    var HANDLE_POSITION = {
        nw: [0, 0, 'nwse-resize'], n: [50, 0, 'ns-resize'], ne: [100, 0, 'nesw-resize'], e: [100, 50, 'ew-resize'],
        se: [100, 100, 'nwse-resize'], s: [50, 100, 'ns-resize'], sw: [0, 100, 'nesw-resize'], w: [0, 50, 'ew-resize'],
    };
    var SNAP_SCREEN_PX = 6;
    var DRAG_THRESHOLD_SCREEN_PX = 3;
    var SAVE_DEBOUNCE_MS = 500;
    var RETRY_MS = 8000;

    root.canvasEditor = function (config) {
        return {
            // ── configuration from the server ───────────────────────────────
            projectId: config.projectId,
            projectWidth: config.projectWidth,
            projectHeight: config.projectHeight,
            canvases: config.canvases,
            activeCanvasId: config.activeCanvasId,
            exportUrlTemplate: config.exportUrlTemplate,
            saveUrlTemplate: config.saveUrlTemplate,
            csrfToken: config.csrfToken,
            assets: config.assets,
            assetIndex: config.assetIndex,
            usage: config.usage,
            uploadUrl: config.uploadUrl,
            assetDeleteUrlTemplate: config.assetDeleteUrlTemplate,
            maxUploadBytes: config.maxUploadBytes,
            maxElements: config.maxElements,
            pngUrlTemplate: config.pngUrlTemplate,
            pngTextAvailable: config.pngTextAvailable,
            pngMaxDimension: config.pngMaxDimension,
            pngMaxPixels: config.pngMaxPixels,

            // ── editor state ────────────────────────────────────────────────
            selectedElementId: null,
            handles: HANDLES,
            zoom: 1,
            zoomMode: 'fit',
            gesture: null,
            lastTap: null,
            guides: { vertical: [], horizontal: [] },
            editorNote: '',
            showShortcuts: false,
            historyTick: 0,
            histories: {},
            snapshots: {},

            // ── saving ──────────────────────────────────────────────────────
            // idle | pending | saving | saved | failed | conflict
            saveState: 'idle',
            saveMessage: '',
            conflictRevision: null,
            dirtyCanvasIds: {},
            version: 0,
            saveTimer: null,
            retryTimer: null,
            savedTimer: null,
            saveInFlight: false,

            // ── asset panel ─────────────────────────────────────────────────
            uploading: false,
            uploadingName: '',
            uploadError: '',
            uploadNote: '',
            dragOver: false,
            confirmDeleteId: null,

            init() {
                var self = this;
                this.canvases.forEach(function (canvas) {
                    self.snapshots[canvas.id] = JSON.stringify(canvas.elements);
                    self.histories[canvas.id] = H.create(100);
                });

                this.$nextTick(function () {
                    self.fit();
                    if (typeof ResizeObserver !== 'undefined' && self.$refs.viewport) {
                        new ResizeObserver(function () { if (self.zoomMode === 'fit') self.fit(); }).observe(self.$refs.viewport);
                    }
                });

                window.addEventListener('beforeunload', function (event) {
                    if (self.hasUnsavedWork()) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                });
            },

            // ── lookups ─────────────────────────────────────────────────────
            activeCanvas() {
                return this.canvases.find(function (c) { return c.id === this.activeCanvasId; }, this) || this.canvases[0];
            },
            activeElements() {
                var canvas = this.activeCanvas();
                return canvas ? canvas.elements : [];
            },
            layersTopFirst() {
                return this.activeElements().slice().reverse();
            },
            selectedElement() {
                return this.activeElements().find(function (el) { return el.id === this.selectedElementId; }, this) || null;
            },
            assetFor(el) {
                return this.assetIndex[el.asset_id] || null;
            },
            layerIcon(el) {
                return { text: 'title', image: 'image' }[el.type] || 'rectangle';
            },
            layerLabel(el) {
                if (el.type === 'text') return (el.text || 'Text').slice(0, 18);
                if (el.type === 'image') return (this.assetFor(el) ? this.assetFor(el).name : 'Image').slice(0, 18);
                return 'Rectangle';
            },
            uuid() {
                return (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'el-' + Date.now() + '-' + Math.random().toString(16).slice(2);
            },

            selectCanvas(id) {
                if (id === this.activeCanvasId) return;
                this.flushSave();
                this.activeCanvasId = id;
                this.selectedElementId = null;
                this.bump();
            },

            // ── rendering ───────────────────────────────────────────────────
            px(value) {
                return (value * this.zoom) + 'px';
            },
            stageStyle() {
                return 'position:relative;margin:16px auto;width:' + this.px(this.projectWidth) + ';height:' + this.px(this.projectHeight) + ';';
            },
            pageStyle() {
                return 'position:absolute;inset:0;overflow:hidden;background:' + this.activeCanvas().background_color + ';box-shadow:0 0 0 1px var(--border);border-radius:2px;';
            },
            boxStyle(el) {
                var style = 'position:absolute;left:' + this.px(el.x) + ';top:' + this.px(el.y) + ';width:' + this.px(el.width) + ';height:' + this.px(el.height) + ';';
                if (el.rotation) style += 'transform:rotate(' + el.rotation + 'deg);transform-origin:center;';
                return style;
            },
            elementStyle(el) {
                var style = this.boxStyle(el) + 'cursor:move;touch-action:none;user-select:none;-webkit-user-select:none;outline:none;';
                if (el.opacity !== undefined && el.opacity !== null && el.opacity < 1) style += 'opacity:' + el.opacity + ';';
                if (el.type === 'text') {
                    style += 'color:' + el.color + ';font-size:' + this.px(el.fontSize) + ';font-family:Inter,Arial,sans-serif;line-height:1.2;display:flex;align-items:center;overflow:hidden;white-space:pre-wrap;overflow-wrap:anywhere;';
                } else if (el.type === 'rectangle') {
                    style += 'background:' + el.fill + ';';
                } else {
                    style += 'display:flex;align-items:center;justify-content:center;';
                }
                return style;
            },
            selectionStyle() {
                var el = this.selectedElement();
                return el ? this.boxStyle(el) + 'outline:1.5px solid #ec4899;pointer-events:none;' : 'display:none;';
            },
            handleStyle(handle) {
                var p = HANDLE_POSITION[handle];
                return 'left:' + p[0] + '%;top:' + p[1] + '%;cursor:' + p[2] + ';';
            },
            guideStyle(kind, value) {
                return kind === 'vertical'
                    ? 'position:absolute;top:0;bottom:0;width:0;border-left:1px solid #ec4899;left:' + this.px(value) + ';pointer-events:none;'
                    : 'position:absolute;left:0;right:0;height:0;border-top:1px solid #ec4899;top:' + this.px(value) + ';pointer-events:none;';
            },

            // ── adding ──────────────────────────────────────────────────────
            canAddElement() {
                if (this.activeElements().length >= this.maxElements) {
                    this.editorNote = 'This page already has ' + this.maxElements + ' elements, the most it can hold.';
                    return false;
                }
                this.editorNote = '';
                return true;
            },
            addElement(el) {
                this.activeElements().push(el);
                this.selectedElementId = el.id;
                this.scheduleSave('add');
            },
            addText() {
                if (! this.canAddElement()) return;
                this.addElement({ id: this.uuid(), type: 'text', x: 40, y: 40, width: 280, height: 56, text: 'Your text', fontSize: 32, color: '#18181b', rotation: 0, opacity: 1 });
            },
            addRectangle() {
                if (! this.canAddElement()) return;
                this.addElement({ id: this.uuid(), type: 'rectangle', x: 40, y: 40, width: 180, height: 120, fill: '#ec4899', rotation: 0, opacity: 1 });
            },
            placeAsset(asset) {
                if (! this.canAddElement()) return;
                // Fit within half the page, keeping the picture's proportions. Placeholders carry no pixel size, so they start square.
                var naturalWidth = asset.width || 400;
                var naturalHeight = asset.height || 400;
                var ratio = Math.min(1, (this.projectWidth * 0.5) / naturalWidth, (this.projectHeight * 0.5) / naturalHeight);
                var width = Math.max(16, Math.round(naturalWidth * ratio));
                var height = Math.max(16, Math.round(naturalHeight * ratio));
                var offset = (this.activeElements().length % 8) * 24;
                this.assetIndex[asset.id] = asset;
                this.addElement({
                    id: this.uuid(), type: 'image', asset_id: asset.id,
                    x: Math.max(0, Math.round((this.projectWidth - width) / 2) - 96 + offset),
                    y: Math.max(0, Math.round((this.projectHeight - height) / 2) - 96 + offset),
                    width: width, height: height, rotation: 0, opacity: 1,
                });
            },

            // ── editing commands ────────────────────────────────────────────
            deleteSelected() {
                if (! this.selectedElementId) return;
                var canvas = this.activeCanvas();
                var id = this.selectedElementId;
                canvas.elements = canvas.elements.filter(function (el) { return el.id !== id; });
                this.selectedElementId = null;
                this.scheduleSave('delete');
            },
            duplicateSelected() {
                var el = this.selectedElement();
                if (! el || ! this.canAddElement()) return;
                var result = G.duplicateElement(this.activeElements(), el.id, this.uuid(), 16);
                if (! result) return;
                this.activeCanvas().elements = result.elements;
                this.selectedElementId = result.element.id;
                this.scheduleSave('duplicate');
            },
            arrange(operation) {
                var el = this.selectedElement();
                if (! el) return;
                this.activeCanvas().elements = G.reorder(this.activeElements(), el.id, operation);
                this.scheduleSave('arrange');
            },
            setNumber(el, key, value, min, max) {
                var number = Number(value);
                if (value === '' || Number.isNaN(number)) return;
                el[key] = Math.min(max, Math.max(min, number));
                this.scheduleSave('prop:' + el.id + ':' + key);
            },
            focusText(el) {
                if (el.type !== 'text') return;
                this.selectedElementId = el.id;
                var self = this;
                this.$nextTick(function () {
                    if (self.$refs.textInput) {
                        self.$refs.textInput.focus();
                        self.$refs.textInput.select();
                    }
                });
            },

            // ── history ─────────────────────────────────────────────────────
            history() {
                var canvas = this.activeCanvas();
                return this.histories[canvas.id];
            },
            bump() {
                this.historyTick += 1;
            },
            canUndo() {
                this.historyTick; // reactive dependency
                return !! this.activeCanvas() && H.canUndo(this.history());
            },
            canRedo() {
                this.historyTick;
                return !! this.activeCanvas() && H.canRedo(this.history());
            },
            // Called after every change to the elements: if they differ from the
            // last remembered state, that state becomes an undo step.
            commit(key) {
                var canvas = this.activeCanvas();
                var before = this.snapshots[canvas.id];
                var now = JSON.stringify(canvas.elements);
                if (before === now) return false;
                H.record(this.history(), JSON.parse(before), { key: key });
                this.snapshots[canvas.id] = now;
                this.bump();
                return true;
            },
            restore(elements) {
                var canvas = this.activeCanvas();
                canvas.elements = elements;
                this.snapshots[canvas.id] = JSON.stringify(elements);
                if (! elements.some(function (el) { return el.id === this.selectedElementId; }, this)) this.selectedElementId = null;
                this.bump();
                this.markDirty();
            },
            undo() {
                var canvas = this.activeCanvas();
                var previous = H.undo(this.history(), canvas.elements);
                if (previous) this.restore(previous);
            },
            redo() {
                var canvas = this.activeCanvas();
                var next = H.redo(this.history(), canvas.elements);
                if (next) this.restore(next);
            },

            // ── pointer gestures (mouse, pen and touch through pointer events) ──
            canvasPoint(event) {
                var rect = this.$refs.page.getBoundingClientRect();
                return { x: (event.clientX - rect.left) / this.zoom, y: (event.clientY - rect.top) / this.zoom };
            },
            beginGesture(event, type, extra) {
                var el = this.selectedElement();
                if (! el) return;
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                var start = this.canvasPoint(event);
                this.gesture = Object.assign({
                    type: type, id: el.id, startX: start.x, startY: start.y, moved: false,
                    box: { x: el.x, y: el.y, width: el.width, height: el.height, rotation: el.rotation || 0 },
                }, extra || {});
                try { this.$refs.stage.setPointerCapture(event.pointerId); } catch (e) { /* capture is a nicety */ }
            },
            startMove(event, el) {
                this.selectedElementId = el.id;
                this.beginGesture(event, 'move');
            },
            startResize(event, handle) {
                this.beginGesture(event, 'resize', { handle: handle });
            },
            startRotate(event) {
                var el = this.selectedElement();
                if (! el) return;
                this.beginGesture(event, 'rotate', { cx: el.x + el.width / 2, cy: el.y + el.height / 2 });
            },
            onStagePointerDown(event) {
                if (event.target === this.$refs.stage || event.target === this.$refs.page) this.selectedElementId = null;
            },
            onPointerMove(event) {
                var gesture = this.gesture;
                if (! gesture) return;
                var el = this.activeElements().find(function (item) { return item.id === gesture.id; });
                if (! el) return;

                var point = this.canvasPoint(event);
                var dx = point.x - gesture.startX;
                var dy = point.y - gesture.startY;

                if (! gesture.moved) {
                    if (Math.abs(dx) + Math.abs(dy) < DRAG_THRESHOLD_SCREEN_PX / this.zoom) return;
                    gesture.moved = true;
                }

                if (gesture.type === 'move') this.applyMove(el, gesture.box, dx, dy, event);
                else if (gesture.type === 'resize') this.applyResize(el, gesture, dx, dy, event);
                else if (gesture.type === 'rotate') el.rotation = G.angleFromPointer(gesture.cx, gesture.cy, point.x, point.y, event.shiftKey);
            },
            applyMove(el, box, dx, dy, event) {
                var next = { x: box.x + dx, y: box.y + dy, width: box.width, height: box.height, rotation: box.rotation };
                if (event.altKey) {
                    this.guides = { vertical: [], horizontal: [] };
                } else {
                    var snapped = G.snapMove(next, this.projectWidth, this.projectHeight, SNAP_SCREEN_PX / this.zoom);
                    next.x = snapped.x;
                    next.y = snapped.y;
                    this.guides = snapped.guides;
                }
                // Keep a corner of the element reachable.
                el.x = Math.round(G.clamp(next.x, -(box.width - 16), this.projectWidth - 16));
                el.y = Math.round(G.clamp(next.y, -(box.height - 16), this.projectHeight - 16));
            },
            applyResize(el, gesture, dx, dy, event) {
                var next = G.resizeBox(gesture.box, gesture.handle, dx, dy, { keepRatio: event.shiftKey, minSize: 8 });
                el.x = Math.round(next.x);
                el.y = Math.round(next.y);
                el.width = Math.round(next.width);
                el.height = Math.round(next.height);
            },
            endGesture() {
                var gesture = this.gesture;
                if (! gesture) return;
                this.gesture = null;
                this.guides = { vertical: [], horizontal: [] };
                if (gesture.moved) {
                    this.scheduleSave('gesture');
                } else if (gesture.type === 'move') {
                    this.registerTap(gesture.id);
                }
            },
            // Two quick taps on a text element edit its text. Detected here rather than with a
            // dblclick listener because pointer capture (needed for dragging) retargets click events.
            registerTap(id) {
                var now = Date.now();
                var el = this.activeElements().find(function (item) { return item.id === id; });
                if (el && this.lastTap && this.lastTap.id === id && now - this.lastTap.at < 400) {
                    this.lastTap = null;
                    this.focusText(el);
                    return;
                }
                this.lastTap = { id: id, at: now };
            },

            // ── keyboard ────────────────────────────────────────────────────
            onKey(event) {
                var target = event.target;
                var typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
                if (typing) return;

                var mod = event.ctrlKey || event.metaKey;
                var key = event.key;
                var lower = key.length === 1 ? key.toLowerCase() : key;

                if (mod && lower === 'z') { event.preventDefault(); if (event.shiftKey) this.redo(); else this.undo(); return; }
                if (mod && lower === 'y') { event.preventDefault(); this.redo(); return; }
                if (mod && lower === 'd') { event.preventDefault(); this.duplicateSelected(); return; }

                var el = this.selectedElement();
                if (! el) return;

                if (key === 'Delete' || key === 'Backspace') { event.preventDefault(); this.deleteSelected(); return; }
                if (key === 'Escape') { this.selectedElementId = null; return; }
                if (key === ']') { event.preventDefault(); this.arrange(mod ? 'front' : 'forward'); return; }
                if (key === '[') { event.preventDefault(); this.arrange(mod ? 'back' : 'backward'); return; }

                if (key.indexOf('Arrow') === 0 && ! mod) {
                    event.preventDefault();
                    var moved = G.nudge(el, key, event.shiftKey);
                    el.x = moved.x;
                    el.y = moved.y;
                    this.scheduleSave('nudge:' + el.id);
                }
            },

            // ── zoom ────────────────────────────────────────────────────────
            zoomLabel() {
                return Math.round(this.zoom * 100) + '%';
            },
            fit() {
                var viewport = this.$refs.viewport;
                if (! viewport) return;
                this.zoomMode = 'fit';
                this.zoom = G.fitZoom(viewport.clientWidth, Math.max(280, window.innerHeight * 0.7), this.projectWidth, this.projectHeight, 16);
            },
            setZoom(next, clientX, clientY) {
                var viewport = this.$refs.viewport;
                var previous = this.zoom;
                next = G.clampZoom(next);
                if (next === previous) return;

                this.zoomMode = 'manual';
                // Keep the canvas point under the pointer (or the viewport centre) where it is.
                var rect = viewport.getBoundingClientRect();
                var anchorX = (clientX === undefined ? rect.left + rect.width / 2 : clientX) - rect.left;
                var anchorY = (clientY === undefined ? rect.top + rect.height / 2 : clientY) - rect.top;
                var canvasX = (viewport.scrollLeft + anchorX) / previous;
                var canvasY = (viewport.scrollTop + anchorY) / previous;

                this.zoom = next;
                this.$nextTick(function () {
                    viewport.scrollLeft = canvasX * next - anchorX;
                    viewport.scrollTop = canvasY * next - anchorY;
                });
            },
            zoomIn() { this.setZoom(G.zoomIn(this.zoom)); },
            zoomOut() { this.setZoom(G.zoomOut(this.zoom)); },
            onWheel(event) {
                if (! event.ctrlKey && ! event.metaKey) return; // plain scrolling scrolls
                event.preventDefault();
                this.setZoom(G.wheelZoom(this.zoom, event.deltaY), event.clientX, event.clientY);
            },

            // ── saving ──────────────────────────────────────────────────────
            scheduleSave(key) {
                this.commit(key || 'edit');
                this.markDirty();
            },
            markDirty() {
                var canvas = this.activeCanvas();
                if (! canvas) return;
                this.dirtyCanvasIds[canvas.id] = true;
                this.version += 1;
                clearTimeout(this.savedTimer);
                if (this.saveState === 'conflict') return; // nothing is sent until the person decides
                this.saveState = 'pending';
                clearTimeout(this.saveTimer);
                this.saveTimer = setTimeout(this.saveAll.bind(this), SAVE_DEBOUNCE_MS);
            },
            flushSave() {
                if (Object.keys(this.dirtyCanvasIds).length === 0 || this.saveState === 'conflict') return;
                clearTimeout(this.saveTimer);
                this.saveAll();
            },
            hasUnsavedWork() {
                return Object.keys(this.dirtyCanvasIds).length > 0 || this.saveInFlight || this.saveState === 'failed' || this.saveState === 'conflict';
            },
            async saveAll() {
                if (this.saveInFlight || this.saveState === 'conflict') return;
                this.saveInFlight = true;
                this.saveState = 'saving';
                clearTimeout(this.retryTimer);
                var versionAtStart = this.version;
                var ok = true;

                try {
                    var ids = Object.keys(this.dirtyCanvasIds).map(Number);
                    for (var i = 0; i < ids.length && ok; i += 1) {
                        ok = await this.saveCanvas(ids[i]);
                    }
                } finally {
                    this.saveInFlight = false;
                }

                if (! ok) return;
                if (this.version !== versionAtStart) { this.markDirty(); return; }

                this.saveState = 'saved';
                this.saveMessage = '';
                var self = this;
                this.savedTimer = setTimeout(function () { if (self.saveState === 'saved') self.saveState = 'idle'; }, 2000);
            },
            async saveCanvas(id) {
                var canvas = this.canvases.find(function (c) { return c.id === id; });
                if (! canvas) { delete this.dirtyCanvasIds[id]; return true; }
                var sentVersion = this.version;

                var response;
                try {
                    response = await fetch(this.saveUrlTemplate.replace('__ID__', id), {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                        body: JSON.stringify({ revision: canvas.revision, elements: canvas.elements, background_color: canvas.background_color }),
                    });
                } catch (e) {
                    return this.failSave('Could not reach the server. Your changes are not saved yet.', true);
                }

                var body = null;
                try { body = await response.json(); } catch (e) { /* not JSON */ }

                if (response.ok && body && body.status === 'saved') {
                    canvas.revision = body.revision;
                    // Changes made while this request was in flight keep the page dirty.
                    if (this.version === sentVersion) delete this.dirtyCanvasIds[id];
                    return true;
                }

                if (response.status === 409) {
                    this.saveState = 'conflict';
                    this.conflictRevision = body ? body.revision : null;
                    this.saveMessage = (body && body.message) || 'This page was changed somewhere else.';
                    return false;
                }
                if (response.redirected || response.status === 401 || response.status === 419) {
                    return this.failSave('Your session has expired. Sign in again in another tab, then press Retry.', false);
                }
                if (response.status === 422) {
                    return this.failSave((body && body.message) || 'The server refused to save this page.', false);
                }
                return this.failSave('Saving failed (HTTP ' + response.status + '). Your changes are not saved yet.', true);
            },
            failSave(message, autoRetry) {
                this.saveState = 'failed';
                this.saveMessage = message;
                clearTimeout(this.retryTimer);
                if (autoRetry) this.retryTimer = setTimeout(this.saveAll.bind(this), RETRY_MS);
                return false;
            },
            retrySave() {
                if (this.saveState === 'failed') this.saveAll();
            },
            reloadLatest() {
                this.dirtyCanvasIds = {};
                window.location.reload();
            },
            // The person's explicit choice: adopt the server's current revision and save over it.
            overwriteWithMine() {
                var canvas = this.activeCanvas();
                if (this.conflictRevision) canvas.revision = this.conflictRevision;
                this.saveState = 'pending';
                this.saveMessage = '';
                this.conflictRevision = null;
                this.saveAll();
            },
            saveLabel() {
                return {
                    idle: '', pending: 'Unsaved changes…', saving: 'Saving…', saved: 'Saved',
                    failed: 'Not saved', conflict: 'Not saved',
                }[this.saveState];
            },

            // ── export availability ─────────────────────────────────────────
            pngBlockedReason() {
                var canvas = this.activeCanvas();
                if (! canvas) return 'Nothing to export.';
                if (this.projectWidth > this.pngMaxDimension || this.projectHeight > this.pngMaxDimension || this.projectWidth * this.projectHeight > this.pngMaxPixels) {
                    return 'PNG export handles canvases up to ' + this.pngMaxDimension + ' px a side. Export SVG instead.';
                }
                if (! this.pngTextAvailable && canvas.elements.some(function (el) { return el.type === 'text' && (el.text || '').trim() !== ''; })) {
                    return 'PNG export cannot draw text on this server (no font configured). Export SVG instead.';
                }
                return '';
            },

            // ── assets panel ────────────────────────────────────────────────
            assetGroups() {
                var self = this;
                return [
                    { key: 'project', label: 'This project', items: this.assets.filter(function (a) { return a.project_id === self.projectId; }) },
                    { key: 'library', label: 'Library', items: this.assets.filter(function (a) { return a.project_id === null; }) },
                ];
            },
            assetMeta(asset) {
                return (asset.width && asset.height ? asset.width + '×' + asset.height + ' · ' : '') + this.formatBytes(asset.size || 0);
            },
            formatBytes(bytes) {
                if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                return Math.round(bytes / 1024) + ' KB';
            },
            async uploadFiles(fileList) {
                var files = Array.from(fileList || []);
                this.uploadError = '';
                this.uploadNote = '';
                for (var i = 0; i < files.length; i += 1) {
                    var file = files[i];
                    if (file.size > this.maxUploadBytes) {
                        this.uploadError = file.name + ' is larger than ' + this.formatBytes(this.maxUploadBytes) + '.';
                        continue;
                    }
                    this.uploading = true;
                    this.uploadingName = file.name;
                    try {
                        var form = new FormData();
                        form.append('file', file);
                        form.append('project_id', this.projectId);
                        var response = await fetch(this.uploadUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                            body: form,
                        });
                        var body = await this.readJson(response);
                        if (! response.ok) {
                            this.uploadError = this.uploadFailureMessage(response, body, file.name);
                            continue;
                        }
                        this.upsertAsset(body.asset);
                        this.usage = body.usage;
                        if (body.duplicate) this.uploadNote = file.name + ' was already in your assets.';
                    } catch (e) {
                        this.uploadError = 'Could not reach the server to upload ' + file.name + '.';
                    } finally {
                        this.uploading = false;
                        this.uploadingName = '';
                    }
                }
            },
            async readJson(response) {
                try { return await response.json(); } catch (e) { return null; }
            },
            uploadFailureMessage(response, body, name) {
                if (body && body.message) return body.message;
                if (response.redirected || response.status === 401) return 'You were signed out. Reload the page and sign in again.';
                if (response.status === 419) return 'Your session expired. Reload the page and try again.';
                if (response.status === 413) return name + ' is larger than the server accepts.';
                return 'Upload failed (HTTP ' + response.status + ').';
            },
            upsertAsset(asset) {
                var index = this.assets.findIndex(function (a) { return a.id === asset.id; });
                if (index === -1) this.assets.unshift(asset); else this.assets.splice(index, 1, asset);
                this.assetIndex[asset.id] = asset;
            },
            async deleteAsset(asset) {
                this.uploadError = '';
                this.uploadNote = '';
                this.confirmDeleteId = null;
                try {
                    var response = await fetch(this.assetDeleteUrlTemplate.replace('__ID__', asset.id), {
                        method: 'DELETE',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                    });
                    var body = await this.readJson(response);
                    if (! response.ok) {
                        this.uploadError = (body && body.message) || 'Could not delete ' + asset.name + ' (HTTP ' + response.status + ').';
                        return;
                    }
                    this.assets = this.assets.filter(function (a) { return a.id !== asset.id; });
                    if (body && body.usage) this.usage = body.usage;
                } catch (e) {
                    this.uploadError = 'Could not reach the server to delete ' + asset.name + '.';
                }
            },
        };
    };
})(window);
