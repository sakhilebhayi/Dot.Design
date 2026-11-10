// Filters, search and sort for the template browser, as plain data: parse them from the address, turn them into the API
// query and back into a shareable address. No DOM here.
export const CATEGORY_TABS = ['marketing', 'social', 'identity', 'commercial', 'document'];
export const FILTER_KEYS = ['industry', 'purpose', 'style', 'platform', 'format', 'objective', 'kind'];
export const SORTS = ['popular', 'newest'];
export const WORKSPACE_SOURCES = ['team', 'user'];

export function defaultState() {
    return { tab: 'all', workspace: 'team', filters: {}, q: '', sort: 'popular' };
}

/** Read a state from a query string ("?category=social&style=bold"). Unknown or empty values are dropped. */
export function parseQuery(search) {
    const params = new URLSearchParams(typeof search === 'string' ? search : '');
    const state = defaultState();
    const source = params.get('source');
    if (WORKSPACE_SOURCES.includes(source)) { state.tab = 'workspace'; state.workspace = source; }
    else if (params.get('tab') === 'workspace') state.tab = 'workspace';
    const category = params.get('category');
    if (state.tab !== 'workspace' && CATEGORY_TABS.includes(category)) state.tab = category;
    for (const key of FILTER_KEYS) {
        const v = (params.get(key) || '').trim();
        if (v && /^[a-z0-9_-]{1,40}$/.test(v)) state.filters[key] = v;
    }
    state.q = (params.get('q') || '').trim().slice(0, 80);
    const sort = params.get('sort');
    if (SORTS.includes(sort)) state.sort = sort;
    return state;
}

/** The query the templates API takes. */
export function buildApiQuery(state, { page = 1, perPage = 24, includePreview = true } = {}) {
    const p = new URLSearchParams();
    if (state.tab === 'workspace') p.set('source', state.workspace);
    else if (CATEGORY_TABS.includes(state.tab)) p.set('category', state.tab);
    for (const key of FILTER_KEYS) if (state.filters[key]) p.set(key, state.filters[key]);
    if (state.q) p.set('q', state.q);
    p.set('sort', state.sort);
    p.set('per_page', String(perPage));
    p.set('page', String(page));
    if (includePreview) p.set('include', 'first_page');
    return p.toString();
}

/** The address shown in the browser bar: only what differs from the defaults, so links stay short. */
export function buildPageQuery(state) {
    const p = new URLSearchParams();
    if (state.tab === 'workspace') p.set('source', state.workspace);
    else if (CATEGORY_TABS.includes(state.tab)) p.set('category', state.tab);
    for (const key of FILTER_KEYS) if (state.filters[key]) p.set(key, state.filters[key]);
    if (state.q) p.set('q', state.q);
    if (state.sort !== 'popular') p.set('sort', state.sort);
    const s = p.toString();
    return s ? `?${s}` : '';
}

/** [{key, value}] for the chips that show which filters are on. */
export function activeFilters(state) {
    const out = [];
    for (const key of FILTER_KEYS) if (state.filters[key]) out.push({ key, value: state.filters[key] });
    if (state.q) out.push({ key: 'q', value: state.q });
    return out;
}

export function withFilter(state, key, value) {
    const next = { ...state, filters: { ...state.filters } };
    if (key === 'q') { next.q = value || ''; return next; }
    if (value) next.filters[key] = value; else delete next.filters[key];
    return next;
}

export function withTab(state, tab) {
    return { ...state, tab: tab === 'workspace' || CATEGORY_TABS.includes(tab) ? tab : 'all' };
}

export function clearAll(state) {
    return { ...defaultState(), tab: state.tab, workspace: state.workspace };
}

/** Whether two states ask for the same list (so a repeat request can be skipped). */
export function sameQuery(a, b) {
    return buildApiQuery(a, { includePreview: false }) === buildApiQuery(b, { includePreview: false });
}
