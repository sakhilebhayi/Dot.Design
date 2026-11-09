// The "describe your idea" call. Contract (docs/R45_UI_CREATE.md, built by the assistant owner):
//   POST design/assistant/compose  {prompt, format_key?}
//   -> {status:'ok', project_url} | {status:'questions', questions:[{id, text}]} | {status:'unavailable', message}
// This file turns any answer, including failures, into one of a few plain outcomes the page can show.
export const MIN_PROMPT = 3;

/** The prompt sent: the idea, plus the person's answers to any follow-up questions. */
export function composePrompt(text, answers = []) {
    const idea = String(text || '').trim();
    const extra = answers.filter((a) => String(a.value || '').trim() !== '').map((a) => `${a.text} ${String(a.value).trim()}`);
    return extra.length ? `${idea}\n\n${extra.join('\n')}` : idea;
}

/**
 * @param {{ok:boolean,status:number,data:any,message:string,aborted?:boolean,offline?:boolean}} res  an api result
 * @returns {{kind:'open',url:string}|{kind:'questions',questions:Array<{id:string,text:string}>}|{kind:'unavailable',message:string}|{kind:'aborted'}|{kind:'error',message:string}}
 */
export function interpretCompose(res) {
    if (res.aborted) return { kind: 'aborted' };
    const d = res.data && typeof res.data === 'object' ? res.data : {};
    if (res.ok && d.status === 'ok' && typeof d.project_url === 'string' && d.project_url.startsWith('/') && !d.project_url.startsWith('//')) return { kind: 'open', url: d.project_url };
    if (res.ok && d.status === 'ok' && typeof d.project_url === 'string') {
        // an absolute address is only followed when it is on this site
        try { const u = new URL(d.project_url, window.location.origin); if (u.origin === window.location.origin) return { kind: 'open', url: u.pathname + u.search }; } catch (e) { /* fall through */ }
    }
    if (res.ok && d.status === 'questions' && Array.isArray(d.questions)) {
        const questions = d.questions.filter((q) => q && typeof q.text === 'string' && q.text.trim() !== '').slice(0, 5).map((q) => ({ id: String(q.id ?? q.text), text: q.text.trim() }));
        if (questions.length) return { kind: 'questions', questions };
    }
    if (d.status === 'unavailable') return { kind: 'unavailable', message: typeof d.message === 'string' && d.message.trim() ? d.message : 'The assistant is not available right now.' };
    return { kind: 'error', message: res.message || 'That did not work. Try again.' };
}
