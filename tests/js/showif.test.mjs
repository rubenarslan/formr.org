// node --test tests/js  (npm run test:js)
// The helpers a v2 showif calls (webroot/assets/form/js/showif/alpine.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isNA } from '../../webroot/assets/form/js/showif/alpine.js';

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
