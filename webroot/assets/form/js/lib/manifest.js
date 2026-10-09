// The run's web app manifest (<link rel="manifest">), fetched once per page
// and shared by the items that read it: add_to_home_screen (app name and
// icon, items/pwa.js) and request_phone (the QR logo, items/request-phone.js).
// Resolves null without a manifest link, or when it cannot be read.
let manifestRequest = null;
export function readManifest() {
    if (!manifestRequest) {
        const link = document.querySelector('link[rel="manifest"]');
        manifestRequest = link
            ? fetch(link.href).then((res) => (res.ok ? res.json() : null)).catch(() => null)
            : Promise.resolve(null);
    }
    return manifestRequest;
}

// The manifest's large icon (192 or 512 px): its src, or null.
export function largeIconSrc(manifest) {
    const icons = manifest && Array.isArray(manifest.icons) ? manifest.icons : [];
    const big = icons.find((ic) => ic.sizes && (ic.sizes.includes('192x192') || ic.sizes.includes('512x512')));
    return (big && big.src) || null;
}
