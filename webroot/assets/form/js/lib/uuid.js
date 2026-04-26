// RFC 4122 v4 UUID generator.
//
// Server regex-rejects anything that isn't 8-4-4-4-12 hex with the v4
// nibble set, so the fallback path mirrors that shape exactly. `crypto
// .randomUUID()` is widely available; the manual path is for very old
// browsers or http-only origins where crypto.randomUUID is missing.

export function genUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : ((r & 0x3) | 0x8);
        return v.toString(16);
    });
}
