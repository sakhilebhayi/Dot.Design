import assert from 'node:assert/strict';
import { test } from 'node:test';
import { activeMention, filterPeople, insertMention, mentionIds, splitMentions } from '../../resources/js/studio/collab/mentions.js';

const people = [
    { id: 1, name: 'Olivia Owner' }, { id: 2, name: 'Adam Admin' }, { id: 3, name: 'Edna Editor' }, { id: 4, name: 'Vic Viewer' }, { id: 5, name: 'Ola Adams' },
];

test('the word being typed after @ is found only at a word boundary', () => {
    assert.deepEqual(activeMention('hi @ed', 6), { start: 3, query: 'ed' });
    assert.deepEqual(activeMention('@', 1), { start: 0, query: '' });
    assert.equal(activeMention('mail me at a@b.co', 17), null);
    assert.equal(activeMention('hi @ed\nnext', 11), null);
    assert.equal(activeMention('no mention here', 5), null);
});

test('filtering matches every typed word, prefers names that start with it, and is capped', () => {
    assert.deepEqual(filterPeople(people, 'ola').map((p) => p.id), [5]);
    assert.deepEqual(filterPeople(people, 'ad').map((p) => p.id), [2, 5]);
    assert.deepEqual(filterPeople(people, 'ola ada').map((p) => p.id), [5]);
    assert.equal(filterPeople(people, '').length, 5);
    assert.equal(filterPeople(people, '', 2).length, 2);
    assert.deepEqual(filterPeople(people, 'zzz'), []);
});

test('choosing a person replaces the typed word and moves the caret after it', () => {
    const r = insertMention('hi @ed there', { start: 3, query: 'ed' }, people[2]);
    assert.equal(r.text, 'hi @Edna Editor  there');
    assert.equal(r.caret, 'hi @Edna Editor '.length);
});

test('only people whose @Name is still in the text are sent as mentions', () => {
    const picked = [{ id: 3, name: 'Edna Editor' }, { id: 2, name: 'Adam Admin' }];
    assert.deepEqual(mentionIds('@Edna Editor please look', picked), [3]);
    assert.deepEqual(mentionIds('nobody', picked), []);
});

test('a saved body is split into plain and mention parts, never HTML', () => {
    const parts = splitMentions('Hi @Edna Editor, see <b>this</b> @Adam Admin', ['Edna Editor', 'Adam Admin']);
    assert.deepEqual(parts.map((p) => [p.text, !!p.mention]), [['Hi ', false], ['@Edna Editor', true], [', see <b>this</b> ', false], ['@Adam Admin', true]]);
    assert.equal(parts.map((p) => p.text).join(''), 'Hi @Edna Editor, see <b>this</b> @Adam Admin');
});

test('overlapping or repeated names do not duplicate text', () => {
    const parts = splitMentions('@Ola Adams and @Ola', ['Ola Adams', 'Ola']);
    assert.equal(parts.map((p) => p.text).join(''), '@Ola Adams and @Ola');
});
