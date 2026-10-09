// The participant's device, as the PWA and phone-request items need to know
// it (v1's components/PWAInstaller.js and form_v2's items share these).
//
// iPadOS Safari asks for the desktop site by default: its user agent is a
// Mac's ("Macintosh; Intel Mac OS X 10_15_7") and names no iOS version. Only
// touch tells it apart, as no Mac has a touch screen.

export function isIOSDevice(nav = navigator) {
    return /iPad|iPhone|iPod/.test(nav.userAgent)
        || (/Macintosh/.test(nav.userAgent) && nav.maxTouchPoints > 1);
}

// Web push came to iOS with 16.4, for home-screen apps. An iPhone's user
// agent carries the system version (OS 16_4); an iPad's desktop one only
// Safari's (Version/16.4), which follows it. A version that cannot be read
// counts as too old.
export function isIOSPushCapable(nav = navigator) {
    if (!isIOSDevice(nav)) return true;
    const m = nav.userAgent.match(/OS (\d+)_(\d+)/) || nav.userAgent.match(/Version\/(\d+)\.(\d+)/);
    if (!m) return false;
    const major = Number(m[1]), minor = Number(m[2]);
    return major > 16 || (major === 16 && minor >= 4);
}

const MOBILE_UA_RE = /Mobi|Android|iPhone|iPad|iPod|BlackBerry|webOS|IEMobile|Opera Mini/i;

export function isMobileDevice(nav = navigator) {
    return MOBILE_UA_RE.test(nav.userAgent) || isIOSDevice(nav);
}
