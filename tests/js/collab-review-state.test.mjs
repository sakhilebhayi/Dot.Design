import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deriveReviewState, latestReview, requestNotice, reviewerChoices } from '../../resources/js/studio/collab/review-state.js';
import { explain, reviewWords } from '../../resources/js/studio/collab/errors.js';

const me = { id: 7 };
const person = (id, name) => ({ id, name });
const review = (over) => ({ id: 1, status: 'pending', requester: person(1, 'Rae Requester'), reviewer: person(7, 'Me'), version: { id: 11 }, note: null, decision_note: null, ...over });
const base = { project: { status: 'draft' }, user: me, permissions: { review: true, approve: true }, role: 'editor', memberCount: 4 };

test('no review means draft, and an editor may request one', () => {
    const s = deriveReviewState({ ...base, reviews: [] });
    assert.equal(s.key, 'draft');
    assert.equal(s.label, 'Draft');
    assert.equal(s.canRequest, true);
    assert.equal(s.canDecide, false);
});

test('every backend status maps to a label and the right controls', () => {
    const cases = [
        ['pending', 'In review', 'accent'],
        ['approved', 'Approved', 'ok'],
        ['changes_requested', 'Changes requested', 'warn'],
        ['stale', 'Changed since approval', 'warn'],
        ['cancelled', 'Draft', 'neutral'],
    ];
    for (const [status, label, tone] of cases) {
        const s = deriveReviewState({ ...base, reviews: [review({ status })] });
        assert.equal(s.label, label, status);
        assert.equal(s.tone, tone, status);
    }
});

test('only the named reviewer is offered Approve and Request changes', () => {
    const asReviewer = deriveReviewState({ ...base, reviews: [review()] });
    assert.equal(asReviewer.canDecide, true);
    assert.equal(asReviewer.canRequest, false, 'cannot request again while one is pending');
    const asOther = deriveReviewState({ ...base, user: { id: 99 }, reviews: [review()] });
    assert.equal(asOther.canDecide, false);
    const noApprove = deriveReviewState({ ...base, permissions: { review: true, approve: false }, reviews: [review()] });
    assert.equal(noApprove.canDecide, false);
});

test('separation of duties: your own request is not yours to approve when others exist, but is in a one-person workspace', () => {
    const own = review({ requester: person(7, 'Me'), reviewer: person(7, 'Me') });
    assert.equal(deriveReviewState({ ...base, reviews: [own], memberCount: 3 }).canDecide, false);
    assert.equal(deriveReviewState({ ...base, reviews: [own], memberCount: 3 }).ownRequest, true);
    assert.equal(deriveReviewState({ ...base, reviews: [own], memberCount: 1 }).canDecide, true);
});

test('the requester and workspace admins can cancel a pending request, others cannot', () => {
    assert.equal(deriveReviewState({ ...base, user: { id: 1 }, reviews: [review()] }).canCancel, true);
    assert.equal(deriveReviewState({ ...base, user: { id: 50 }, role: 'admin', reviews: [review()] }).canCancel, true);
    assert.equal(deriveReviewState({ ...base, user: { id: 50 }, role: 'editor', reviews: [review()] }).canCancel, false);
});

test('after an edit the approval is stale, the banner says so in the agreed words and a new review can be requested', () => {
    const s = deriveReviewState({ ...base, reviews: [review({ id: 1, status: 'approved' }), review({ id: 2, status: 'stale' })] });
    assert.equal(s.key, 'stale');
    assert.equal(s.banner.text, 'This design changed after it was approved. Ask for a new review.');
    assert.equal(s.canRequest, true);
});

test('approved offers the final download', () => {
    const s = deriveReviewState({ ...base, reviews: [review({ status: 'approved' })] });
    assert.equal(s.canDownloadFinal, true);
    assert.equal(s.canRequest, false);
});

test('the newest review decides the state', () => {
    assert.equal(latestReview([review({ id: 3 }), review({ id: 9 }), review({ id: 5 })]).id, 9);
    const s = deriveReviewState({ ...base, reviews: [review({ id: 1, status: 'changes_requested' }), review({ id: 2, status: 'pending' })] });
    assert.equal(s.key, 'in_review');
});

test('an archived design offers no review actions', () => {
    const s = deriveReviewState({ ...base, project: { status: 'archived' }, reviews: [] });
    assert.equal(s.key, 'archived');
    assert.equal(s.canRequest, false);
});

test('changes requested shows the reviewer note', () => {
    const s = deriveReviewState({ ...base, reviews: [review({ status: 'changes_requested', decision_note: 'Logo too small' })] });
    assert.equal(s.banner.note, 'Logo too small');
});

test('a notification is never claimed as sent, whatever the integration says', () => {
    for (const notify of [null, { status: 'connected' }, { status: 'ready' }, { status: 'not_configured' }, { status: 'unavailable' }]) {
        const text = requestNotice('Ben', notify);
        assert.match(text, /Review inbox/);
        assert.doesNotMatch(text, /was sent|has been sent|were sent|notified/i);
    }
});

test('reviewer choices exclude yourself and people who cannot approve, except when alone', () => {
    const people = [
        { id: 7, name: 'Me', is_you: true, can_approve: true },
        { id: 8, name: 'Ann', is_you: false, can_approve: true },
        { id: 9, name: 'Ed', is_you: false, can_approve: false },
    ];
    assert.deepEqual(reviewerChoices(people, 7).map((p) => p.id), [8]);
    assert.deepEqual(reviewerChoices([people[0]], 7).map((p) => p.id), [7]);
    assert.deepEqual(reviewerChoices([people[0], people[2]], 7), []);
});

test('review refusals are translated into plain language', () => {
    assert.match(reviewWords('You cannot approve your own request. Ask a colleague to review it.'), /colleague has to approve/);
    assert.match(reviewWords('Choose someone else to review this: you cannot review your own request.'), /Pick a colleague/);
    assert.match(reviewWords('That person cannot approve designs in this workspace.'), /look at designs but not approve/);
    assert.match(reviewWords('This review has already been closed (approved), so it cannot be changed.'), /already been answered or cancelled/);
    assert.equal(reviewWords('Something the server said that we do not know'), 'Something the server said that we do not know');
    const e = explain({ ok: false, status: 403, message: 'You cannot approve your own request. Ask a colleague to review it.' }, { review: true, what: 'send your answer' });
    assert.match(e.text, /colleague has to approve/);
});
