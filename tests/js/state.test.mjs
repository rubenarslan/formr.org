// node --test tests/js  (npm run test:js)
// The pure navigation rules of form_v2: webroot/assets/form/js/state.mjs.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore, pageState } from '../../webroot/assets/form/js/state.mjs';

const it = (name, o = {}) => Object.assign({ name, visible: true, answerable: true }, o);
const block = (items) => ({ unit_session_id: 7, items });
const st = (answered, extra = {}) => Object.assign({ id: 1, page: 1, required: true, answered, saved: answered, value: answered ? 'x' : null, hidden: false }, extra);

const snap = [
    { page: 1, items: [it('q1'), it('q2', { visible: false })] },
    { page: 2, items: [it('q3')] },
    { page: 3, items: [it('note', { answerable: false }), it('q4')] },
];

test('fresh form opens on page 1 and steps through every shown page', () => {
    const store = createStore(block({ q1: st(false), q3: st(false), q4: st(false) }));
    const ps = pageState(snap, store, null, false);
    assert.equal(ps.currentPage, 1);
    assert.deepEqual(ps.shownPages, [1, 2, 3]);
    assert.equal(ps.nextAfter(1), 2);
    assert.equal(ps.nextAfter(3), null, 'page 3 is the last: its submit is final');
    assert.equal(ps.isFirstShown(1), true);
    assert.equal(ps.isLastShown(3), true);
    assert.deepEqual(ps.progress(2), { index: 2, total: 3 });
});

test('a remembered page wins when it is still shown', () => {
    const store = createStore(block({ q1: st(true), q3: st(false), q4: st(false) }));
    assert.equal(pageState(snap, store, 3, false).currentPage, 3);
    assert.equal(pageState(snap, store, 99, false).currentPage, 2, 'unknown page: fall back');
});

test('without memory: first page with a visible answerable item lacking an answer', () => {
    const store = createStore(block({ q1: st(true), q2: st(false), q3: st(true), q4: st(false) }));
    assert.equal(pageState(snap, store, null, false).currentPage, 3, 'q2 hidden, q3 answered → page 3');
    const done = createStore(block({ q1: st(true), q3: st(true), q4: st(true) }));
    assert.equal(pageState(snap, done, null, false).currentPage, 3, 'everything answered: last shown page');
    const skipped = createStore(block({ q1: st(false, { saved: true }), q3: st(false), q4: st(false) }));
    assert.equal(pageState(snap, skipped, null, false).currentPage, 2, 'q1 left empty but stored with its page: not reopened');
});

test('a page whose items are all hidden is stepped over', () => {
    const s = [
        { page: 1, items: [it('q1')] },
        { page: 2, items: [it('q3', { visible: false })] },
        { page: 3, items: [it('q4')] },
    ];
    const ps = pageState(s, createStore(block({})), null, true);
    assert.deepEqual(ps.shownPages, [1, 3]);
    assert.equal(ps.nextAfter(1), 3);
    assert.equal(ps.previousOf(3), 1);
    assert.equal(ps.isLastShown(3), true);
    assert.deepEqual(ps.progress(3), { index: 2, total: 2 });
});

test('previous only when the study allows it', () => {
    const store = createStore(block({}));
    assert.equal(pageState(snap, store, null, true).previousOf(2), 1);
    assert.equal(pageState(snap, store, null, false).previousOf(2), null);
    assert.equal(pageState(snap, store, null, true).previousOf(1), null);
});

test('store: merge a reply, mark a queued submission, expose values', () => {
    const store = createStore(block({ q1: st(false, { id: 11 }), q2: st(false, { id: 12 }) }));
    assert.equal(store.unitSessionId, 7);
    store.merge({ q1: { answered: true, value: '2', hidden: false } });
    assert.equal(store.items.q1.answered, true);
    assert.equal(store.items.q1.id, 11, 'merge keeps what the reply did not send');
    store.markQueued({ q2: ['a', 'b'], q1: '' }, [12]);
    assert.equal(store.items.q2.answered, true);
    assert.equal(store.items.q2.hidden, true);
    assert.equal(store.items.q1.answered, false, 'an empty post is not an answer');
    assert.equal(store.items.q1.hidden, false);
    assert.deepEqual(store.values(), { q1: null, q2: ['a', 'b'] });
});
