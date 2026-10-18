/*
 * Pure geometry for the canvas editor: resize/rotate maths, snapping, zoom,
 * z-order, duplicate. No DOM and no Alpine, so it can be unit-tested in Node
 * (npm run test:js) and is loaded as a plain classic script in the browser --
 * it has to exist before Livewire starts Alpine, which an ES module would not.
 *
 * Conventions: x/y is the top-left of an element's UNROTATED box, rotation is
 * degrees clockwise about its centre, y grows downward. Handles are named by
 * compass point: n ne e se s sw w nw.
 */
(function (root) {
    'use strict';

    var ZOOM_MIN = 0.25;
    var ZOOM_MAX = 4;

    function norm(value) {
        return value + 0; // turns -0 into 0
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    // Rotate the vector (x, y) by `deg` clockwise on screen.
    function rotateVector(x, y, deg) {
        var rad = (deg * Math.PI) / 180;
        var cos = Math.cos(rad);
        var sin = Math.sin(rad);
        return { x: x * cos - y * sin, y: x * sin + y * cos };
    }

    function rotatePoint(x, y, cx, cy, deg) {
        var v = rotateVector(x - cx, y - cy, deg);
        return { x: cx + v.x, y: cy + v.y };
    }

    /*
     * New box after dragging `handle` by the WORLD-space pointer delta (dx, dy).
     * The delta is moved into the element's own (unrotated) frame, the size is
     * changed there, and the centre is shifted so the side opposite the handle
     * stays exactly where it was on screen -- that is what makes dragging a
     * rotated element feel right. keepRatio only applies to corner handles.
     */
    function resizeBox(box, handle, dx, dy, options) {
        var opts = options || {};
        var minSize = opts.minSize === undefined ? 8 : opts.minSize;
        var rotation = box.rotation || 0;
        var sx = handle.indexOf('e') !== -1 ? 1 : handle.indexOf('w') !== -1 ? -1 : 0;
        var sy = handle.indexOf('s') !== -1 ? 1 : handle.indexOf('n') !== -1 ? -1 : 0;
        var local = rotateVector(dx, dy, -rotation);

        var width = box.width + sx * local.x;
        var height = box.height + sy * local.y;

        if (opts.keepRatio && sx !== 0 && sy !== 0) {
            var scale = Math.max(width / box.width, height / box.height);
            scale = Math.max(scale, minSize / box.width, minSize / box.height);
            width = box.width * scale;
            height = box.height * scale;
        } else {
            width = Math.max(minSize, width);
            height = Math.max(minSize, height);
        }

        var shift = rotateVector((sx * (width - box.width)) / 2, (sy * (height - box.height)) / 2, rotation);
        var cx = box.x + box.width / 2 + shift.x;
        var cy = box.y + box.height / 2 + shift.y;

        return { x: norm(cx - width / 2), y: norm(cy - height / 2), width: norm(width), height: norm(height) };
    }

    // Rotation (degrees, 0 = handle straight above the centre) that points the rotate handle at the pointer.
    function angleFromPointer(cx, cy, px, py, snap) {
        var deg = (Math.atan2(px - cx, -(py - cy)) * 180) / Math.PI;
        if (snap) deg = Math.round(deg / 15) * 15;
        return norm(Math.round(deg));
    }

    function nudge(box, key, shift) {
        var step = shift ? 10 : 1;
        var x = box.x;
        var y = box.y;
        if (key === 'ArrowLeft') x -= step;
        else if (key === 'ArrowRight') x += step;
        else if (key === 'ArrowUp') y -= step;
        else if (key === 'ArrowDown') y += step;
        return { x: x, y: y };
    }

    // Axis-aligned bounds of a possibly rotated box.
    function boundsOf(box) {
        var rotation = box.rotation || 0;
        var cx = box.x + box.width / 2;
        var cy = box.y + box.height / 2;
        var corners = [
            rotatePoint(box.x, box.y, cx, cy, rotation),
            rotatePoint(box.x + box.width, box.y, cx, cy, rotation),
            rotatePoint(box.x + box.width, box.y + box.height, cx, cy, rotation),
            rotatePoint(box.x, box.y + box.height, cx, cy, rotation),
        ];
        var xs = corners.map(function (c) { return c.x; });
        var ys = corners.map(function (c) { return c.y; });
        return { left: Math.min.apply(null, xs), right: Math.max.apply(null, xs), top: Math.min.apply(null, ys), bottom: Math.max.apply(null, ys) };
    }

    function bestSnap(points, targets, threshold) {
        var best = null;
        for (var i = 0; i < points.length; i += 1) {
            for (var j = 0; j < targets.length; j += 1) {
                var diff = targets[j] - points[i];
                if (Math.abs(diff) <= threshold && (best === null || Math.abs(diff) < Math.abs(best.diff))) {
                    best = { diff: diff, target: targets[j] };
                }
            }
        }
        return best;
    }

    /*
     * Snap a box being moved so its left/centre/right (and top/middle/bottom)
     * line up with the canvas edges and centre when within `threshold` canvas
     * pixels. Returns the adjusted x/y and the guide positions to draw.
     */
    function snapMove(box, canvasWidth, canvasHeight, threshold) {
        var bounds = boundsOf(box);
        var snapX = bestSnap([bounds.left, (bounds.left + bounds.right) / 2, bounds.right], [0, canvasWidth / 2, canvasWidth], threshold);
        var snapY = bestSnap([bounds.top, (bounds.top + bounds.bottom) / 2, bounds.bottom], [0, canvasHeight / 2, canvasHeight], threshold);

        return {
            x: norm(box.x + (snapX ? snapX.diff : 0)),
            y: norm(box.y + (snapY ? snapY.diff : 0)),
            guides: {
                vertical: snapX ? [snapX.target] : [],
                horizontal: snapY ? [snapY.target] : [],
            },
        };
    }

    function clampZoom(zoom) {
        if (typeof zoom !== 'number' || Number.isNaN(zoom)) return 1;
        return clamp(zoom, ZOOM_MIN, ZOOM_MAX);
    }

    function zoomIn(zoom) {
        return clampZoom(zoom * 1.25);
    }

    function zoomOut(zoom) {
        return clampZoom(zoom / 1.25);
    }

    function fitZoom(containerWidth, containerHeight, canvasWidth, canvasHeight, padding) {
        var pad = padding || 0;
        return clampZoom(Math.min((containerWidth - 2 * pad) / canvasWidth, (containerHeight - 2 * pad) / canvasHeight));
    }

    function wheelZoom(zoom, deltaY) {
        return clampZoom(zoom * Math.exp(-deltaY * 0.0015));
    }

    // z-order: the array is bottom-to-top. Returns a new array.
    function reorder(elements, id, operation) {
        var list = elements.slice();
        var index = list.findIndex(function (el) { return el.id === id; });
        if (index === -1) return list;

        var item = list.splice(index, 1)[0];
        var target = index;
        if (operation === 'forward') target = Math.min(list.length, index + 1);
        else if (operation === 'backward') target = Math.max(0, index - 1);
        else if (operation === 'front') target = list.length;
        else if (operation === 'back') target = 0;

        list.splice(target, 0, item);
        return list;
    }

    function duplicateElement(elements, id, newId, offset) {
        var index = elements.findIndex(function (el) { return el.id === id; });
        if (index === -1) return null;

        var copy = JSON.parse(JSON.stringify(elements[index]));
        copy.id = newId;
        if (typeof copy.x === 'number') copy.x += offset;
        if (typeof copy.y === 'number') copy.y += offset;

        var list = elements.slice();
        list.splice(index + 1, 0, copy);
        return { elements: list, element: copy };
    }

    root.DotDesign = root.DotDesign || {};
    root.DotDesign.geometry = {
        ZOOM_MIN: ZOOM_MIN,
        ZOOM_MAX: ZOOM_MAX,
        clamp: clamp,
        rotatePoint: rotatePoint,
        resizeBox: resizeBox,
        angleFromPointer: angleFromPointer,
        nudge: nudge,
        boundsOf: boundsOf,
        snapMove: snapMove,
        clampZoom: clampZoom,
        zoomIn: zoomIn,
        zoomOut: zoomOut,
        fitZoom: fitZoom,
        wheelZoom: wheelZoom,
        reorder: reorder,
        duplicateElement: duplicateElement,
    };
})(typeof window !== 'undefined' ? window : this);
