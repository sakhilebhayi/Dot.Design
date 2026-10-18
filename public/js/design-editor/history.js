/*
 * Undo/redo for the canvas editor: a capped stack of snapshots of the
 * elements array, kept in memory only (a reload starts a fresh history).
 * Pure functions over a plain object, loaded as a classic script and unit
 * tested in Node (npm run test:js).
 */
(function (root) {
    'use strict';

    var DEFAULT_LIMIT = 100;
    var COALESCE_MS = 700;

    function copy(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function create(limit) {
        return { past: [], future: [], limit: limit || DEFAULT_LIMIT, lastKey: null, lastAt: 0 };
    }

    /*
     * Remember `before` (the state prior to a change) as an undo step.
     * Changes that carry the same `key` and arrive within COALESCE_MS of the
     * previous one -- typing in a field, nudging with an arrow key -- merge
     * into a single step, so one undo reverses the whole burst.
     */
    function record(history, before, options) {
        var opts = options || {};
        var now = opts.now === undefined ? Date.now() : opts.now;
        var key = opts.key || null;

        var coalesce = key !== null && history.lastKey === key && now - history.lastAt <= COALESCE_MS && history.past.length > 0;
        history.lastAt = now;
        history.lastKey = key;
        history.future = [];

        if (coalesce) return false;

        history.past.push(copy(before));
        while (history.past.length > history.limit) history.past.shift();
        return true;
    }

    function canUndo(history) {
        return history.past.length > 0;
    }

    function canRedo(history) {
        return history.future.length > 0;
    }

    // Step back: returns the previous state (or null) and keeps `current` for redo.
    function undo(history, current) {
        if (!canUndo(history)) return null;
        history.future.push(copy(current));
        history.lastKey = null;
        return history.past.pop();
    }

    function redo(history, current) {
        if (!canRedo(history)) return null;
        history.past.push(copy(current));
        history.lastKey = null;
        return history.future.pop();
    }

    function clear(history) {
        history.past = [];
        history.future = [];
        history.lastKey = null;
    }

    root.DotDesign = root.DotDesign || {};
    root.DotDesign.history = { create: create, record: record, canUndo: canUndo, canRedo: canRedo, undo: undo, redo: redo, clear: clear };
})(typeof window !== 'undefined' ? window : this);
