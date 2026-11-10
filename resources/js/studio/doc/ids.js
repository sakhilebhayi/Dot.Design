/*
 * Collision-safe element and page ids. Ids match the schema's uuid_or_id
 * pattern ^[A-Za-z0-9_-]{1,64}$. Tests pass a seed so a run is reproducible;
 * the browser uses crypto.getRandomValues.
 */

const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';
export const ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

function mulberry32(seed) {
    let a = seed >>> 0;
    return function () {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function randomSource(seed) {
    if (seed !== undefined && seed !== null) return mulberry32(seed);
    const c = typeof globalThis !== 'undefined' ? globalThis.crypto : null;
    if (c && typeof c.getRandomValues === 'function') {
        return function () {
            const buf = new Uint32Array(1);
            c.getRandomValues(buf);
            return buf[0] / 4294967296;
        };
    }
    return Math.random;
}

/**
 * createIdGenerator({seed?, prefix?}) -> { next(taken?): string }
 * `taken` is a Set (or anything with .has) of ids that must not be returned.
 */
export function createIdGenerator(options = {}) {
    const rand = randomSource(options.seed);
    const prefix = options.prefix || 'e';
    return {
        next(taken) {
            for (let attempt = 0; attempt < 64; attempt += 1) {
                let s = '';
                for (let i = 0; i < 10; i += 1) s += ALPHABET[Math.floor(rand() * ALPHABET.length)];
                const id = `${prefix}_${s}`;
                if (!taken || !taken.has(id)) return id;
            }
            // 64 collisions in a row cannot happen with a sane random source; stay total anyway.
            let n = 0;
            while (taken && taken.has(`${prefix}_x${n}`)) n += 1;
            return `${prefix}_x${n}`;
        },
    };
}

export function isValidId(id) {
    return typeof id === 'string' && ID_PATTERN.test(id);
}
