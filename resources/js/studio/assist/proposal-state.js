/*
 * The assistant panel's state machine for ONE proposal at a time. Pure reducer plus a tiny store so the UI and the
 * tests share it. Rules it enforces:
 *   - nothing is applied unless the person pressed Apply while a proposal was on screen (phase 'review');
 *   - a response that arrives after the person dismissed, or after a newer request, is ignored (token check);
 *   - a failed apply leaves the proposal on screen so it can be retried or dismissed; the design is untouched.
 *
 * phase: idle | working | review | applying | error | unavailable | limit | nothing
 */

export const PHASES = ['idle', 'working', 'review', 'applying', 'error', 'unavailable', 'limit', 'nothing'];

export const initial = () => ({ phase: 'idle', token: 0, source: null, label: '', proposal: null, message: '', retryable: false, usage: null, notice: '' });

export function reduce(state, event) {
    switch (event.type) {
        case 'ask':
            if (state.phase === 'working' || state.phase === 'applying') return state;
            return { ...initial(), usage: state.usage, token: state.token + 1, phase: 'working', source: event.source, label: event.label || '' };

        case 'proposal':
            if (event.token !== state.token || state.phase !== 'working') return state;
            return { ...state, phase: 'review', proposal: event.proposal, message: '' };

        case 'nothing':
            if (event.token !== state.token || state.phase !== 'working') return state;
            return { ...state, phase: 'nothing', message: event.message || 'There is nothing to change.' };

        case 'unavailable':
            if (event.token !== state.token || state.phase !== 'working') return state;
            return { ...state, phase: 'unavailable', message: event.message };

        case 'limit':
            if (event.token !== state.token || state.phase !== 'working') return state;
            return { ...state, phase: 'limit', message: event.message, usage: event.usage || state.usage };

        case 'fail':
            if (event.token !== state.token || state.phase !== 'working') return state;
            return { ...state, phase: 'error', message: event.message, retryable: event.retryable !== false };

        case 'apply':
            return state.phase === 'review' && state.proposal ? { ...state, phase: 'applying', message: '' } : state;

        case 'applied':
            return state.phase === 'applying' ? { ...initial(), usage: state.usage, token: state.token, notice: event.notice || 'Applied. You can undo it.' } : state;

        case 'apply-failed':
            return state.phase === 'applying' ? { ...state, phase: 'review', message: event.message } : state;

        case 'dismiss':
            if (state.phase === 'working' || state.phase === 'applying') return state.phase === 'working' ? { ...initial(), usage: state.usage, token: state.token + 1 } : state;
            return { ...initial(), usage: state.usage, token: state.token };

        case 'usage':
            return { ...state, usage: event.usage };

        case 'clear-notice':
            return state.notice ? { ...state, notice: '' } : state;

        default:
            return state;
    }
}

export function createMachine(start = initial()) {
    let state = start;
    const listeners = new Set();
    return {
        get state() { return state; },
        send(event) {
            const next = reduce(state, event);
            if (next !== state) { state = next; listeners.forEach((fn) => fn(state, event)); }
            return state;
        },
        subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    };
}
