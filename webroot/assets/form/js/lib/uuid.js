// RFC 4122 v4 UUID generator.
//
// Used by the offline queue (./offline/queue.js) as a per-submission
// idempotency key written into the `uuid` field of POST /form-page-submit
// and persisted in survey_form_submissions.uuid (UNIQUE). The server treats
// retries with the same UUID as already-applied (FK CASCADE on unit_session_id
// keeps the ledger scoped). This is NOT a security primitive — collisions
// merely dedupe the same submission. We still pull randomness from the Web
// Crypto API because it's available everywhere the modern v2 stack runs, and
// it keeps `js/insecure-randomness` quiet without needing a CodeQL suppression.
//
// Server-side regex rejects anything that isn't 8-4-4-4-12 hex with the v4
// nibble set, so all paths produce that exact shape.

export function genUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        // RFC 4122 §4.4: set version (4) and variant (10xx).
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }
    // Web Crypto missing — every browser that supports the v2 stack
    // (Alpine, fetch, IndexedDB) also has at least getRandomValues, but
    // throw rather than silently fall back to Math.random so a future
    // regression surfaces instead of writing predictable UUIDs.
    throw new Error('genUuid: Web Crypto API unavailable');
}
