// node --test tests/js  (npm run test:js)
// The helpers a v2 showif calls (webroot/assets/form/js/showif/alpine.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isNA, contains } from '../../webroot/assets/form/js/showif/alpine.js';

test('isNA: nothing given is NA, an array of nothing included', () => {
    assert.equal(isNA(null), true);
    assert.equal(isNA(''), true);
    assert.equal(isNA([]), true);
    // An untouched geopoint ([''] coerced) and touch-mode rank.
    assert.equal(isNA([null]), true);
    assert.equal(isNA([null, null, null]), true);
    assert.equal(isNA(0), false);
    assert.equal(isNA([null, 2]), false, 'an mc_multiple read through its placeholder, one box ticked');
    assert.equal(isNA(['a']), false);
});

test('contains: a ticked choice, written as a number or as text', () => {
    // The scope holds numeric choices as numbers (coerceField).
    assert.equal(contains([1, 2], 2), true);
    assert.equal(contains([1, 2], '2'), true, 'the documented contains(x, "2")');
    assert.equal(contains([12], '2'), false, 'a choice, not a digit of one');
    assert.equal(contains(['cat', 'dog'], 'cat'), true);
    assert.equal(contains('a cat', 'cat'), true, 'text: substring');
    assert.equal(contains(null, 2), false);
});
