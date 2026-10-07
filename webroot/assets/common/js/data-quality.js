// formr data quality: automation and carelessness indicators for a survey page.
//
// Nothing here runs unless a page holds a `data_quality` item (DataQuality_Item
// renders <input type="hidden" data-fmr-dq>). Importing the module does
// nothing. The two adapters — initDataQuality() at the end of this file for the
// classic engine, form/js/items/data-quality.js for form_v2 — look for that
// input first and create a recorder only when they find one in use (liveFields:
// a row its showif has switched off is not). A recorder is what adds the
// listeners, wraps HTMLElement.prototype.focus and Element.prototype
// .scrollIntoView, lets error stacks run to 50 frames, observes DOM changes and
// asks which DRM key systems exist. A study without the item is untouched, and
// so is a consent page, which never carries one.
//
// A recorder keeps one record per page: a flat object of counts, durations,
// yes/no values and a few names from fixed lists (the codebook is on the
// admin documentation page, templates/admin/documentation/data_quality.php,
// and blankRecord() below lists every field). No keystrokes or key timing series,
// no mouse paths, no clipboard content, no IP address, no device fingerprint,
// no full user agent, no list of extensions.

import { isMobileDevice } from './platform.js';

// Bumped whenever a field is added, dropped or changes meaning.
export const RECORD_VERSION = 2;
export const FIELD_SELECTOR = 'input[data-fmr-dq]';
// The data_quality inputs within `scope` that are in use. A showif that hides
// an item disables its inputs (both engines: the item is withdrawn, and
// nothing of it is posted); such a row records nothing either.
export function liveFields(scope) {
    return Array.prototype.filter.call(scope.querySelectorAll(FIELD_SELECTOR), (field) => !field.disabled);
}

// Tool names looked for in page globals and in elements/attributes injected into the page.
const TOOL_RX = /playwright|puppeteer|selenium|webdriver|cdc_|\$wdc_|phantom|nightmare|cypress|claude|anthropic|browser-?use|stagehand|skyvern|nanobrowser|multion|harpa|comet|perplexity|chatgpt|openai|manus/i;
const AI_BROWSER_RX = /comet|atlas|chatgpt|openai|perplexity|genspark|fellou|\bdia\b|neon/i;
// Agent overlays and markers (Skyvern unique_id, __pw-cursor, Stagehand's cursor, a Prolific AI
// solver's status box) and AI writing-assistant extensions.
const AGENT_MARK_RX = /^unique_id$|__pw-cursor|__v3_cursor|prolific-ai-status/i;
const AI_EXT_RX = /(?:^|[\s_#.:-])(merlin|sider|maxai|monica|quillbot|jetwriter|magical|c4g)(?=$|[\s_.:-])/i;
// formr's own survey markup: the form and, on the classic engine, the block
// around it, which carries the study's name as a class. Ids and classes in
// there hold names an author chose (the study's, a choice's key in an input's
// id, an item's classes, a label's HTML): a study about ChatGPT is not a tool.
export const OWN_MARKUP = 'form.main_formr_survey, form.fmr-form-v2, [class*="study-name-"]';
const EXT_IDS = { camppjleccjaphfdbohjdohecfnoikec: 'merlin' };
const KNOWN_GLOBALS = ['__playwright__binding__', '__pwInitScripts', '__playwright_evaluation_script__',
    '__puppeteer_evaluation_script__', 'callPhantom', '_phantom', '__nightmare', 'domAutomation',
    'domAutomationController', '_Selenium_IDE_Recorder', '_selenium', 'callSelenium', '__webdriver_evaluate',
    '__selenium_evaluate', '__webdriver_script_function', '__webdriver_script_func', '__webdriver_script_fn',
    '__fxdriver_evaluate', '__driver_unwrapped', '__webdriver_unwrapped', '__driver_evaluate',
    '__selenium_unwrapped', '__fxdriver_unwrapped', '__lastWatirAlert', 'Cypress'];
// The Chrome version that shipped each JS feature. Named as strings and read
// from a window of their own (nativeFeatureRange): the bundle's polyfills would
// answer for the browser, and a property written out here would pull one in.
const VERSION_FEATURES = [[97, 'Array.prototype.findLast'], [98, 'structuredClone'], [110, 'Array.prototype.toSorted'],
    [117, 'Object.groupBy'], [119, 'Promise.withResolvers'], [121, 'Array.fromAsync'], [122, 'Set.prototype.union'],
    [128, 'Promise.try'], [129, 'Intl.DurationFormat'], [135, 'Float16Array'], [136, 'RegExp.escape']];

// The tool names (lower case) that a tag, an id, a class or an attribute's name holds.
export function toolNames(str) {
    const m = str.match(TOOL_RX) || str.match(AGENT_MARK_RX), x = str.match(AI_EXT_RX), names = [];
    if (m) names.push(m[0].toLowerCase());
    if (x) names.push(x[1].toLowerCase());
    return names;
}
// What of an element is read for tool names: its tag wherever it is, its id
// and classes only outside the survey's own markup (OWN_MARKUP). The names of
// its attributes are read everywhere (checkEl): a tool that marks the survey's
// inputs does it with an attribute.
export function elementNames(el) {
    return el.closest && el.closest(OWN_MARKUP) ? el.tagName : el.tagName + ' ' + (el.id || '') + ' ' + (el.getAttribute('class') || '');
}

// A record with nothing counted, in the order its fields are stored.
export function blankRecord() {
    return {
        v: RECORD_VERSION, ld: 0,
        // environment / automation traces
        wd: 0, wdt: 0, hl: 0, nochr: 0, plg0: 0, lang0: 0, ow0: 0, scrvp: 0, swgl: 0, perm: 0,
        glob: '', ext: '', ext_res: 0, aib: '', fam: '', mob: 0, ptr: '', fx0: 0, vis0: '', uac: '', uah: '', a11y: 0, winscr: 0,
        eng: '', eng_ua: '', eng_mm: 0, ver_mm: 0, drm: 'na', chr_nowv: 0,
        // time
        t: 0, t1: -1,
        // leaving the page
        hid_n: 0, hid_ms: 0, blur_n: 0, blur_ms: 0,
        // copy / paste
        copy: 0, copy_q: 0, copy_ch: 0, cut: 0, paste: 0, paste_ch: 0, ctx: 0,
        // keyboard and text
        keys: 0, kn: 0, kfast: 0, kdwf: 0, kcv: -1, kwb: -1, bksp: 0, kcap: 0, kcap_ns: 0, kcap_nosk: 0, kcode0: 0, txt_ch: 0, fld_nokey: 0,
        ins_na: 0, kwc: 0, kws: 0, klp: 0, klp_ws: 0,
        ins_nokey: 0, ins_nokey_ch: 0, ins_repl: 0, ins_other: 0,
        // pointer
        mv: 0, mv_jump: 0, mv_coal: 0, mv_mz: 0, pd: 0, pd_fewmv: 0, pd_ctr: 0, pd_scr: 0, pu: 0, pd_hold0: 0, pd_newview: 0,
        tap: 0, tap_r1: 0,
        // scroll / focus / resize
        scr: 0, scr_nogest: 0, foc_nogest: 0, foc_prog: 0, clk_nopt: 0, rsz: 0, rsz_side: 0, rsz_bar: 0,
        // tampering
        untr: 0, prog: 0, act_hid: 0, act_unf: 0, dom_top: 0,
    };
}

// The record an input holds, or null when it holds none of ours.
export function parseRecord(value) {
    try {
        const r = JSON.parse(value || 'null');
        return r && typeof r === 'object' && !Array.isArray(r) && r.v ? r : null;
    } catch (e) {
        return null;
    }
}

// Yes/no values: a page seen twice says yes when either visit did.
const FLAGS = ['wd', 'wdt', 'hl', 'nochr', 'plg0', 'lang0', 'ow0', 'scrvp', 'swgl', 'perm', 'mob', 'a11y', 'winscr', 'eng_mm', 'ver_mm', 'chr_nowv'];
// Lists of names: joined.
const LISTS = ['glob', 'ext', 'uac'];
// Read as they are now, or handled below.
const AS_NOW = ['v', 'fx0', 't1', 'kcv', 'kwb', 'mv_coal'];

// What a page's record is once `live` (this document's visit) is added to
// `base` (the record stored for the page before: a reload, a page shown again
// after a validation error, a form reopened). Counts and durations add up.
export function mergeRecords(base, live) {
    if (!base) return live;
    const out = Object.assign({}, live);
    Object.keys(live).forEach((k) => {
        if (AS_NOW.indexOf(k) >= 0) return;
        if (LISTS.indexOf(k) >= 0) {
            const names = {};
            String(base[k] || '').split(',').concat(String(live[k] || '').split(',')).forEach((n) => { if (n) names[n] = 1; });
            out[k] = Object.keys(names).sort().join(',');
        } else if (typeof live[k] === 'number' && typeof base[k] === 'number') {
            out[k] = FLAGS.indexOf(k) >= 0 ? Math.max(base[k], live[k]) : base[k] + live[k];
        }
    });
    // The first action on the page is the first visit's.
    if (typeof base.t1 === 'number' && base.t1 >= 0) out.t1 = base.t1;
    // -1: this browser reports no coalesced pointer samples.
    if (typeof base.mv_coal === 'number') out.mv_coal = base.mv_coal < 0 || live.mv_coal < 0 ? -1 : base.mv_coal + live.mv_coal;
    // Typing regularity: the most regular visit (-1: too little typed to say).
    if (typeof base.kcv === 'number' && base.kcv >= 0) out.kcv = live.kcv >= 0 ? Math.min(base.kcv, live.kcv) : base.kcv;
    if (typeof base.kwb === 'number' && base.kwb >= 0 && !(live.kwb >= 0)) out.kwb = base.kwb;
    return out;
}

// --- Who called? -----------------------------------------------------------
// Code an agent runs in the page (page.evaluate, a CDP Runtime.evaluate, an
// injected snippet) has no script file: its stack frames read `<anonymous>` or
// `eval`. The page's own code — this bundle, wherever it is served from, and
// the run's own scripts on the page's origin — has. The recorder takes
// `new Error().stack` inside its focus() wrapper and inside its listeners for
// script-made events; the first frame is then that wrapper or listener itself,
// and names the bundle's own address.

const FRAME_RX = /:\d+:\d+\)?\s*$/;
const frameUrl = (line) => {
    const m = /([a-z][a-z0-9+.-]*:\/\/[^\s()]*?):\d+:\d+\)?\s*$/i.exec(line);
    return m ? m[1] : '';
};
const originOf = (url) => {
    const m = /^([a-z][a-z0-9+.-]*:\/\/[^/]*)/i.exec(url || '');
    return m ? m[1] : '';
};

// 'none': no script called (the browser did); 'own': only the page's own code
// is on the stack; 'mixed': the page's own code, called by code that is not
// (an agent's click() running a widget's handler); 'foreign': scripts called,
// and none of them is the page's.
export function callerKind(stack, pageOrigin) {
    const callers = callersOwn(stack, pageOrigin);
    if (!callers.length) return 'none';
    const mine = callers.filter(Boolean).length;
    return mine === callers.length ? 'own' : mine ? 'mixed' : 'foreign';
}
// Did the page's own code begin the call? A stack's last frame is where it
// began: a handler or a timer of the page's, or code that is not the page's
// (an agent's evaluate) and may then have gone through the page's own code to
// act, as with the classic engine's global jQuery: $(el).click().
export function startedByOwn(stack, pageOrigin) {
    const callers = callersOwn(stack, pageOrigin);
    return callers.length > 0 && callers[callers.length - 1];
}
// Every frame of a stack but the first (the recorder's own): is it the page's?
function callersOwn(stack, pageOrigin) {
    const frames = String(stack || '').split('\n').filter((l) => FRAME_RX.test(l));
    const own = [originOf(frameUrl(frames[0] || '')), pageOrigin].filter(Boolean);
    return frames.slice(1).map((line) => {
        const url = frameUrl(line);
        return own.some((o) => url.indexOf(o + '/') === 0);
    });
}

// --- Small maths -----------------------------------------------------------

// Coefficient of variation of key intervals (-1: fewer than five).
function variation(a) {
    if (a.length < 5) return -1;
    const m = a.reduce((s, x) => s + x, 0) / a.length;
    const sd = Math.sqrt(a.reduce((s, x) => s + (x - m) * (x - m), 0) / (a.length - 1));
    return m > 0 ? Math.round(sd / m * 100) / 100 : -1;
}
function median(a) {
    const b = a.slice().sort((x, y) => x - y), h = Math.floor(b.length / 2);
    return b.length % 2 ? b[h] : (b[h - 1] + b[h]) / 2;
}
// Adler-32 of a string, as hex: compared in R with the User-Agent header a
// `browser` item stores.
export function adler32(text) {
    let a = 1, b = 0;
    for (let i = 0; i < text.length; i++) { a = (a + text.charCodeAt(i)) % 65521; b = (b + a) % 65521; }
    return (b * 65536 + a).toString(16);
}

// --- Environment (read once per document) ----------------------------------

// The range of Chrome versions the browser's own JS features allow, read from
// the window of a fresh iframe (see VERSION_FEATURES). null: no such window.
function nativeFeatureRange() {
    let frame = null;
    try {
        frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.tabIndex = -1;
        frame.style.display = 'none';
        (document.body || document.documentElement).appendChild(frame);
        const w = frame.contentWindow;
        let lo = 0, hi = 9999;
        VERSION_FEATURES.forEach((f) => {
            let has = false;
            try { has = !!f[1].split('.').reduce((o, k) => (o == null ? o : o[k]), w); } catch (e) { /* absent */ }
            if (has) lo = Math.max(lo, f[0]); else hi = Math.min(hi, f[0] - 1);
        });
        return { lo, hi };
    } catch (e) {
        return null;
    } finally {
        if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
    }
}

// Which JS engine runs the page. A changed User-Agent cannot alter how the
// engine words errors, prints stacks and native functions, or which
// engine-only APIs exist.
function realEngine() {
    const votes = { blink: 0, gecko: 0, webkit: 0 };
    try { JSON.parse('null').x; } catch (e) {
        const em = String(e && e.message);
        if (/Cannot read propert/.test(em)) votes.blink += 2;
        else if (/can't access property|is null|has no properties/.test(em)) votes.gecko += 2;
        else if (/is not an object/.test(em)) votes.webkit += 2;
    }
    try {
        if (/^\s+at /m.test(new Error('x').stack || '')) votes.blink++; else { votes.gecko += 0.5; votes.webkit += 0.5; }
        if (/\n/.test(Function.prototype.toString.call(Math.max))) { votes.gecko += 0.5; votes.webkit += 0.5; } else votes.blink++;
    } catch (e) { /* no vote */ }
    if ('mozInnerScreenX' in window) votes.gecko++;
    if ('GestureEvent' in window) votes.webkit++;
    if ('onbeforeinstallprompt' in window || 'webkitRequestFileSystem' in window) votes.blink++;
    const rank = Object.keys(votes).sort((x, y) => votes[y] - votes[x]);
    return votes[rank[0]] >= 2 && votes[rank[0]] > votes[rank[1]] ? rank[0] : 'other';
}

// User-agent coherence: the UA string against navigator.platform and the
// client hints. Returns the names of the checks that failed.
function userAgentIncoherence(ua) {
    const uaOS = /Windows/.test(ua) ? 'win' : /Android/.test(ua) ? 'android' : /iPhone|iPad|iPod/.test(ua) ? 'ios'
        : /CrOS/.test(ua) ? 'cros' : /Macintosh|Mac OS X/.test(ua) ? 'mac' : /Linux/.test(ua) ? 'linux' : 'other';
    const np = navigator.platform || '', fails = [];
    const npOS = /^Win/.test(np) ? 'win' : /^Mac/.test(np) ? 'mac' : /^(iPhone|iPad|iPod)/.test(np) ? 'ios' : /Linux|Android/.test(np) ? 'linux' : '';
    if (npOS && npOS !== uaOS && !(npOS === 'linux' && /android|cros|linux/.test(uaOS)) && !(npOS === 'mac' && uaOS === 'ios')) fails.push('platform');
    const uad = navigator.userAgentData, cm = ua.match(/Chrome\/(\d+)/);
    if (cm && !/Firefox\//.test(ua) && window.isSecureContext) {
        if (!uad || !uad.brands || !uad.brands.length) {
            // (a Chrome before 90 has no client hints to give)
            if (+cm[1] >= 90) fails.push('nohints');
        } else {
            const chromium = uad.brands.filter((b) => b.brand === 'Chromium')[0];
            if (chromium && chromium.version !== cm[1]) fails.push('version');
            const hp = { macOS: 'mac', Windows: 'win', Linux: 'linux', Android: 'android', 'Chrome OS': 'cros', 'Chromium OS': 'cros' }[uad.platform];
            if (hp && hp !== uaOS && !(/android|linux/.test(hp) && /android|linux/.test(uaOS))) fails.push('hintplatform');
            if (typeof uad.mobile === 'boolean' && uad.mobile !== /Mobi/.test(ua)) fails.push('hintmobile');
        }
    }
    return fails.join(',');
}

// Everything read from the browser once. The DRM and permission answers
// arrive later and are written into the same object.
function readEnvironment() {
    const E = {};
    const ua = navigator.userAgent || '';
    let brands = [];
    try { brands = ((navigator.userAgentData && navigator.userAgentData.brands) || []).map((b) => b.brand); } catch (e) { /* none */ }
    E.fam = /Edg\//.test(ua) ? 'edge' : /OPR\//.test(ua) ? 'opera' : /Firefox\//.test(ua) ? 'firefox'
        : /Chrome\//.test(ua) ? 'chrome' : /Safari\//.test(ua) ? 'safari' : 'other';
    // A phone or a tablet: the checks that need a physical keyboard, a mouse
    // or a window are off there. A tablet does not always say what it is. An
    // iPad asks for the desktop site and calls itself a Mac; only its touch
    // screen tells (platform.js). Chrome on a large Android tablet does the
    // same and calls itself a Linux PC, down to its client hints; what it
    // cannot hide is that its pointer is a finger and nothing hovers (a
    // laptop with a touch screen still has its mouse first).
    let touchFirst = false;
    try {
        touchFirst = (navigator.maxTouchPoints || 0) > 0 && matchMedia('(pointer: coarse)').matches && !matchMedia('(hover: hover)').matches;
    } catch (e) { /* unsupported query */ }
    E.mob = isMobileDevice(navigator) || (navigator.userAgentData && navigator.userAgentData.mobile) || touchFirst ? 1 : 0;
    E.ptr = matchMedia('(pointer: fine)').matches ? 'fine' : matchMedia('(pointer: coarse)').matches ? 'coarse' : 'none';
    E.wd = navigator.webdriver === true ? 1 : 0;
    E.wdt = 0;
    try { // webdriver flag patched by a stealth script (own property or non-native getter)
        const d1 = Object.getOwnPropertyDescriptor(navigator, 'webdriver');
        const d2 = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(navigator), 'webdriver');
        E.wdt = d1 || (d2 && d2.get && !/\[native code\]/.test(Function.prototype.toString.call(d2.get))) ? 1 : 0;
    } catch (e) { /* unreadable */ }
    E.hl = /HeadlessChrome/.test(ua) || brands.some((b) => /Headless/i.test(b)) ? 1 : 0;
    E.nochr = /Chrome\//.test(ua) && !/Edg\/|OPR\//.test(ua) && !window.chrome ? 1 : 0;
    E.plg0 = /Chrome\//.test(ua) && !E.mob && navigator.plugins && navigator.plugins.length === 0 ? 1 : 0;
    E.lang0 = !navigator.languages || navigator.languages.length === 0 ? 1 : 0;
    E.a11y = 0;
    try {
        E.a11y = ['(forced-colors: active)', '(prefers-contrast: more)', '(prefers-reduced-motion: reduce)']
            .some((q) => matchMedia(q).matches) ? 1 : 0;
    } catch (e) { /* unsupported query */ }
    E.fx0 = document.hasFocus() ? 1 : 0;
    E.vis0 = document.visibilityState;
    E.uac = userAgentIncoherence(ua);
    E.uah = adler32(ua);
    const aib = brands.concat([ua]).join(' ').match(AI_BROWSER_RX);
    E.aib = aib ? aib[0].toLowerCase() : '';
    E.eng = realEngine();
    // every browser on iOS uses WebKit, whatever its name
    E.eng_ua = /iPhone|iPad|iPod|CriOS|FxiOS|EdgiOS|OPiOS/.test(ua) ? 'webkit' : /Firefox\//.test(ua) ? 'gecko'
        : /Chrome\/|Chromium\//.test(ua) ? 'blink' : /AppleWebKit/.test(ua) && /Safari\//.test(ua) ? 'webkit' : 'other';
    E.eng_mm = E.eng !== 'other' && E.eng_ua !== 'other' && E.eng !== E.eng_ua ? 1 : 0;
    // the Chrome version claimed against the range its JS features allow
    const cm = ua.match(/Chrome\/(\d+)/), claimed = cm ? +cm[1] : 0;
    E.ver_mm = 0;
    if (E.eng === 'blink' && E.eng_ua === 'blink' && claimed) {
        const range = nativeFeatureRange();
        E.ver_mm = range && (claimed < range.lo || claimed > range.hi) ? 1 : 0;
    }
    // Which DRM key systems exist (availability only: no licence request, no
    // device identifier). Google Chrome loads Widevine; a Chrome launched by
    // Playwright or browser-use, Chrome for Testing and CloakBrowser lack it.
    // Desktop Blink and WebKit only: Firefox shows a "this site needs DRM" bar
    // when DRM is off, Android Chrome may prompt. Not Brave either: Widevine
    // is off there until asked for, and asking makes Brave offer to install
    // it (it does not claim to be Google Chrome, so nothing is lost). A
    // Permissions-Policy that disallows encrypted-media would make every
    // Chrome look like one without Widevine, so then nothing is asked and
    // nothing flagged.
    E.drm = 'na';
    E.chr_nowv = 0;
    if (!E.mob && !navigator.brave && (E.eng === 'blink' || E.eng === 'webkit') && window.isSecureContext && navigator.requestMediaKeySystemAccess) {
        let allowed = true;
        try {
            const policy = document.permissionsPolicy || document.featurePolicy;
            if (policy && policy.allowsFeature) allowed = policy.allowsFeature('encrypted-media');
        } catch (e) { /* no policy API */ }
        if (!allowed) {
            E.drm = 'blocked';
        } else {
            const cfg = [{ initDataTypes: ['cenc', 'sinf', 'skd'], videoCapabilities: [{ contentType: 'video/mp4; codecs="avc1.42E01E"' }] }];
            const ks = (name) => {
                try { return navigator.requestMediaKeySystemAccess(name, cfg).then(() => 1, () => 0); } catch (e) { return Promise.resolve(0); }
            };
            Promise.all([ks('com.widevine.alpha'), ks('com.microsoft.playready.recommendation'), ks('com.apple.fps')]).then((x) => {
                E.drm = ['wv', 'pr', 'fp'].filter((n, i) => x[i]).join(',');
                E.chr_nowv = brands.indexOf('Google Chrome') >= 0 && !x[0] ? 1 : 0;
            }, () => {});
        }
    }
    E.swgl = -1;
    try {
        const gl = document.createElement('canvas').getContext('webgl');
        if (gl) {
            const dbg = gl.getExtension('WEBGL_debug_renderer_info');
            const rend = String(gl.getParameter(dbg ? dbg.UNMASKED_RENDERER_WEBGL : gl.RENDERER) || '');
            E.swgl = /swiftshader|llvmpipe|softpipe|software|basic render/i.test(rend) ? 1 : 0;
            // The context was only made to ask: give its memory back now.
            const lose = gl.getExtension('WEBGL_lose_context');
            if (lose) lose.loseContext();
        }
    } catch (e) { /* no WebGL */ }
    E.perm = 0;
    try {
        if (window.Notification && navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'notifications' }).then((p) => {
                E.perm = Notification.permission === 'denied' && p.state === 'prompt' ? 1 : 0;
            }, () => {});
        }
    } catch (e) { /* no permissions API */ }
    return E;
}

// --- Recorder --------------------------------------------------------------

const isTextField = (el) => !!el && (el.tagName === 'TEXTAREA'
    || (el.tagName === 'INPUT' && /^(text|search|email|number|tel|url|password)?$/i.test(el.type || '')));
// A text field a person types the answer into: not one that a widget of the
// page fills (a geopoint's read-only coordinates, the input behind a
// select_or_add's list, the clickable map's).
const isTypedField = (el) => isTextField(el) && !el.readOnly
    && !(el.matches && el.matches('.select2add, .clickable_map input'));
// How much of a text is new: its length less what it still shares, at its
// start and at its end, with the text it started from. A stored answer that
// comes back with its page, or a default, was not written on this visit.
export function newChars(text, before) {
    const max = Math.min(text.length, before.length);
    let head = 0, tail = 0;
    while (head < max && text.charCodeAt(head) === before.charCodeAt(head)) head++;
    while (tail < max - head && text.charCodeAt(text.length - 1 - tail) === before.charCodeAt(before.length - 1 - tail)) tail++;
    const fresh = text.slice(head, text.length - tail);
    // What is left of a text that parts were taken from, at both ends, is not new either.
    return text.length <= before.length && before.indexOf(fresh) >= 0 ? 0 : fresh.length;
}
// Does a key pressed after `prevKey`, in the field `sameField` says, start a
// word? After a space or punctuation, on a new line, and in another field
// than the key before: people pause there.
export function startsWord(prevKey, sameField) {
    return !sameField || prevKey === 'Enter' || /^[\s.,;:!?]$/.test(prevKey);
}
// The item an element belongs to.
const group = (el) => (el && el.closest ? el.closest('.form-group, fieldset') || el : null);
// The inputs that hold answers (not ours, not the bookkeeping ones).
const controls = (scope) => Array.prototype.filter.call(scope.querySelectorAll('input, select, textarea'),
    (el) => el.name && !el.hasAttribute('data-fmr-dq') && !/^(hidden|submit|button|image|reset)$/i.test(el.type || ''));
const valueOf = (name) => {
    const els = document.getElementsByName(name), out = [];
    for (let i = 0; i < els.length; i++) {
        const el = els[i];
        if (el.type === 'radio' || el.type === 'checkbox') { if (el.checked) out.push(el.value); } else out.push(el.value);
    }
    return out.join('|');
};

// Creating a recorder is what switches the checks on for this document. It
// records nothing until enter() names the page on screen, and only while a
// page is entered.
//   enter(scope, base)  `scope` (an element, or the document) is now the page
//                       on screen; `base` is the record stored for it before.
//   leave()             no recorded page is on screen.
//   record(scope, final) the page's record so far; `final` also rescans the
//                       page for automation traces.
export function createRecorder() {
    const now = () => performance.now();
    // callerKind and startedByOwn ask who began a call: the last frames of a
    // stack. V8 keeps ten unless told otherwise, fewer than a widget's way
    // from its API to the event it makes (tom-select's setValue), and the
    // caller would be cut off.
    try { if (typeof Error.stackTraceLimit === 'number' && Error.stackTraceLimit < 50) Error.stackTraceLimit = 50; } catch (e) { /* not settable */ }
    const env = readEnvironment();
    const tools = {}, extHits = {};
    const pages = [];
    let cur = null;      // the page on screen, when it is a recorded one
    let scanning = null; // the page a scan started by record() counts for
    let scanned = false; // the document has been looked over once
    const counting = () => scanning || cur;
    const pageOf = (el) => {
        for (let i = 0; i < pages.length; i++) { if (pages[i].scope.contains(el)) return pages[i]; }
        return null;
    };

    // Document-wide timing state.
    let lastGestureT = -1e9, lastKeyT = 0, lastKeydownT = -1e9, composing = false;
    let keyDown = {}, prevKey = '', prevKeyEl = null, downT = null;
    let shiftDownT = -1, shiftUpT = 0, deadT = -1e9, lastKeyName = '', activateT = -1e9;
    let mx = null, my = null, moveTimes = [];
    let hiddenSince = null, blurSince = null;
    let refocusT = -1e9, ownFocusT = -1e9, ownScrollT = -1e9, lastScrollGestureT = -1e9;
    // A script-made event that is not the page's is being handled: what the
    // page's own code does until that script is done is its reaction.
    let reacting = false;
    // Whose act a stack says it is (callerKind). The page's own code with a
    // caller that is not the page's ('mixed') is the page reacting while that
    // caller's event is being handled. With no such event, and the call begun
    // by code that is not the page's, it is that code acting through the
    // page's own (the classic engine's global jQuery: $(el).click()): foreign.
    const actor = (stack) => {
        const kind = callerKind(stack, location.origin);
        return kind === 'mixed' && !reacting && !startedByOwn(stack, location.origin) ? 'foreign' : kind;
    };
    let lastIW = innerWidth, lastIH = innerHeight, lastOW = outerWidth, lastOH = outerHeight;

    function leave() {
        if (!cur) return;
        const t = now(), c = cur.c;
        c.t += t - cur.enteredAt;
        if (hiddenSince !== null) c.hid_ms += t - hiddenSince;
        if (blurSince !== null) c.blur_ms += t - blurSince;
        hiddenSince = blurSince = null;
        cur.enteredAt = null;
        cur = null;
    }
    function enter(scope, base) {
        if (cur && cur.scope === scope) return;
        leave();
        let p = pages.filter((q) => q.scope === scope)[0];
        if (!p) {
            // `c`: what this document counted on the page; the typing rhythm
            // and the items touched are kept per page as well.
            p = { scope, base: base || null, c: blankRecord(), keyIntervals: [], ivWord: [], ivBoundary: [],
                touched: [], keysIn: [], pastedIn: [], initialVal: {}, enteredAt: null };
            controls(scope).forEach((el) => { p.initialVal[el.name] = valueOf(el.name); });
            pages.push(p);
        }
        const t = now();
        p.c.ld++;
        p.enteredAt = t;
        hiddenSince = document.visibilityState === 'hidden' ? t : null;
        blurSince = document.hasFocus() ? null : t;
        cur = p;
        // What is on the page as recording starts: a marker an agent removes
        // again before the page is sent would otherwise go unseen.
        if (!scanned) {
            scanned = true;
            scanGlobals(); fullScan();
        }
    }

    // ---------- automation globals and injected DOM ----------
    function scanGlobals() {
        KNOWN_GLOBALS.forEach((g) => { try { if (g in window) tools[g] = 1; } catch (e) { /* guarded property */ } });
        try {
            Object.getOwnPropertyNames(window).concat(Object.getOwnPropertyNames(document)).forEach((k) => {
                if (/^\$?cdc_|^\$wdc_|^__opensin|playwright|puppeteer|selenium|webdriver/i.test(k) && k !== 'webdriver') tools[k] = 1;
            });
            ['webdriver', 'selenium', 'driver'].forEach((a) => { if (document.documentElement.hasAttribute(a)) tools['html@' + a] = 1; });
        } catch (e) { /* unreadable */ }
    }
    // The same few names come up for every element and on every scan: each is
    // matched once.
    const namesRead = new Set();
    const markName = (str) => {
        if (namesRead.has(str)) return;
        namesRead.add(str);
        toolNames(str).forEach((name) => { extHits[name] = 1; });
    };
    const seenRes = typeof WeakSet === 'function' ? new WeakSet() : null;
    function checkEl(el, top) {
        if (!el || el.nodeType !== 1) return;
        const p = counting();
        markName(elementNames(el));
        // (as strings where the browser gives them: no attribute objects to make for every element)
        if (el.getAttributeNames) el.getAttributeNames().forEach((name) => markName(name));
        else for (let i = 0; i < el.attributes.length; i++) markName(el.attributes[i].name);
        // resources an extension injected into the page (counted; only known AI-tool IDs are named)
        const res = el.getAttribute('src') || el.getAttribute('href') || '';
        const em = res.match(/^(?:chrome|moz|safari-web)-extension:\/\/([a-z0-9-]+)/i);
        if (em && seenRes && !seenRes.has(el)) {
            seenRes.add(el);
            if (p) p.c.ext_res++;
            if (EXT_IDS[em[1]]) extHits[EXT_IDS[em[1]]] = 1;
        }
        if (top && p) p.c.dom_top++;
    }
    function fullScan() {
        const all = document.getElementsByTagName('*');
        for (let i = 0; i < all.length; i++) checkEl(all[i], false);
    }
    try {
        new MutationObserver((muts) => {
            if (!cur) return;
            muts.forEach((m) => {
                if (m.type === 'attributes') {
                    if (m.attributeName) markName(m.attributeName);
                    return;
                }
                for (let i = 0; i < m.addedNodes.length; i++) {
                    const n = m.addedNodes[i];
                    const top = m.target === document.body || m.target === document.documentElement;
                    checkEl(n, top && n.nodeType === 1 && n.tagName !== 'SCRIPT' && n.tagName !== 'STYLE' && n.tagName !== 'LINK');
                }
            });
        }).observe(document.documentElement, { childList: true, subtree: true, attributes: true });
    } catch (e) { /* no observer */ }

    // ---------- helpers for the listeners ----------
    const firstAction = () => {
        const c = cur.c;
        if (c.t1 >= 0) return;
        c.t1 = Math.round(c.t + now() - cur.enteredAt);
        c.ow0 = window.outerWidth === 0 || window.outerHeight === 0 ? 1 : 0;
        c.scrvp = !env.mob && screen.width === window.innerWidth && screen.height === window.innerHeight ? 1 : 0;
        c.winscr = !env.mob && (window.outerWidth > screen.width + 20 || window.outerHeight > screen.height + 20) ? 1 : 0;
    };
    // An item counts as touched once it received a trusted click, tap, key
    // press, input or change; the page's own widgets (button groups, number
    // fields) then copy values into the real inputs.
    const bump = (arr, el, n) => {
        const g = group(el);
        for (let i = 0; i < arr.length; i++) { if (arr[i].g === g) { arr[i].n += n; return; } }
        arr.push({ g, n });
    };
    const countIn = (arr, el) => {
        const g = group(el);
        for (let i = 0; i < arr.length; i++) { if (arr[i].g === g) return arr[i].n; }
        return 0;
    };
    const touch = (el) => { const g = group(el); if (g && cur.touched.indexOf(g) < 0) cur.touched.push(g); };
    const gesture = (e) => { lastGestureT = now(); touch(e.target); };
    const noWindow = () => document.visibilityState === 'hidden' || window.outerWidth === 0;
    const unfocusedAction = () => {
        if (noWindow()) cur.c.act_hid++;
        else if (!document.hasFocus()) cur.c.act_unf++;
    };
    // A script-made event from the page's own code, acting on its own: when
    // it announces a value the page wrote into an item nobody touched (an R
    // default), that value is the item's starting point, not an answer given
    // without interaction. A widget set off by a foreign click is no such
    // case. Chrome and Firefox show the foreign caller on the stack
    // (callerKind "mixed"); WebKit shows no frame for code that has no file,
    // so there it is known by the foreign event still being handled.
    const ownWrite = (el) => {
        const p = el && el.name ? pageOf(el) : null;
        if (p && p.touched.indexOf(group(el)) < 0) p.initialVal[el.name] = valueOf(el.name);
    };

    // ---------- focus() called by code that is not the page's ----------
    try {
        const origFocus = HTMLElement.prototype.focus;
        HTMLElement.prototype.focus = function () {
            try {
                // Taken here, in the wrapper itself: see callerKind.
                const kind = actor(new Error().stack);
                if (kind === 'own' || kind === 'mixed') ownFocusT = now();
                else if (kind === 'foreign' && cur) cur.c.foc_prog++;
            } catch (err) { /* no stack */ }
            return origFocus.apply(this, arguments);
        };
    } catch (e) { /* not patchable */ }
    // The page's own scroll to something (the item a server reply found
    // wrong) is not the participant scrolling without a gesture.
    try {
        const origScrollIntoView = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = function () {
            try {
                // Taken here, in the wrapper itself: see callerKind.
                const kind = actor(new Error().stack);
                if (kind === 'own' || kind === 'mixed') ownScrollT = now();
            } catch (err) { /* no stack */ }
            return origScrollIntoView.apply(this, arguments);
        };
    } catch (e) { /* not patchable */ }

    // ---------- pointer ----------
    document.addEventListener('pointermove', (e) => {
        if (!cur || e.pointerType === 'touch') return;
        const t = now(), c = cur.c;
        c.mv++;
        if (mx !== null && Math.pow(e.clientX - mx, 2) + Math.pow(e.clientY - my, 2) > 250 * 250) c.mv_jump++;
        // real high-rate mice deliver several samples per animation frame; protocol-dispatched moves deliver one
        if (e.getCoalescedEvents) { if (c.mv_coal >= 0 && e.getCoalescedEvents().length > 1) c.mv_coal++; } else c.mv_coal = -1;
        if (mx !== null && (e.clientX !== mx || e.clientY !== my) && e.movementX === 0 && e.movementY === 0) c.mv_mz++;
        mx = e.clientX; my = e.clientY;
        moveTimes.push(t);
        if (moveTimes.length > 50) moveTimes.shift();
    }, { capture: true, passive: true });

    // When did clickable elements become visible? (agents scroll a target into view and click it at once)
    let seenSince = typeof WeakMap === 'function' ? new WeakMap() : null;
    const observed = seenSince ? new WeakMap() : null;
    try {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((en) => {
                if (en.isIntersecting) { if (!seenSince.has(en.target)) seenSince.set(en.target, now()); } else seenSince.delete(en.target);
            });
        });
        // (not the hidden inputs: nothing of theirs can come into view or be clicked)
        Array.prototype.forEach.call(document.querySelectorAll('form button, form input:not([type=hidden]), form textarea, form select, form label, form .btn'), (el) => {
            observed.set(el, 1); io.observe(el);
        });
    } catch (e) { seenSince = null; }
    const newlyVisible = (el) => {
        if (!seenSince) return false;
        for (let k = 0; el && el.nodeType === 1 && k < 5; k++, el = el.parentElement) {
            if (observed.has(el)) { const t = seenSince.get(el); return t === undefined || now() - t < 200; }
        }
        return false;
    };
    const isCenterClick = (e) => {
        let el = e.target;
        for (let k = 0; el && el.nodeType === 1 && k < 5; k++, el = el.parentElement) {
            const r = el.getBoundingClientRect();
            if (r.width >= 6 && r.height >= 6 && Math.abs(e.clientX - (r.left + r.width / 2)) <= 1
                && Math.abs(e.clientY - (r.top + r.height / 2)) <= 1) return true;
        }
        return false;
    };
    // Script-made events. `stack` is taken by the listener itself (callerKind):
    // the page's own widgets make such events too, and those are not counted —
    // not even when a foreign click set the widget off, which was counted as
    // that click. Returns who made the event (actor).
    const scriptMade = (stack) => {
        const kind = actor(stack);
        if (kind === 'foreign' || kind === 'none') {
            cur.c.untr++;
            if (!reacting) {
                reacting = true;
                Promise.resolve().then(() => { reacting = false; });
            }
        }
        return kind;
    };
    document.addEventListener('pointerdown', (e) => {
        if (!cur) return;
        if (!e.isTrusted) { scriptMade(new Error().stack); return; }
        const c = cur.c;
        firstAction(); gesture(e);
        if (noWindow()) c.act_hid++;
        if (e.pointerType === 'touch') { c.tap++; return; }
        c.pd++;
        downT = now();
        if (newlyVisible(e.target)) c.pd_newview++;
        let recent = 0;
        for (let i = moveTimes.length - 1; i >= 0 && downT - moveTimes[i] < 1000; i--) recent++;
        if (recent <= 2) c.pd_fewmv++;
        if (isCenterClick(e)) c.pd_ctr++;
        if (e.screenX === e.clientX && e.screenY === e.clientY) c.pd_scr++;
    }, true);
    document.addEventListener('pointerup', (e) => {
        if (!cur || !e.isTrusted || e.pointerType === 'touch' || downT === null) return;
        cur.c.pu++;
        if (now() - downT < 25) cur.c.pd_hold0++; // human clicks last ~50-150 ms
        downT = null;
    }, true);
    document.addEventListener('touchstart', (e) => {
        if (!cur || !e.isTrusted) return;
        for (let i = 0; i < e.changedTouches.length; i++) {
            const tt = e.changedTouches[i];
            if (typeof tt.radiusX === 'number' && tt.radiusX <= 1 && tt.radiusY <= 1) cur.c.tap_r1++;
        }
    }, { capture: true, passive: true });
    // The control of the label a pointer last clicked, and when: the browser
    // passes that click on to the control, as a second click of its own.
    let labelControl = null, labelT = -1e9;
    document.addEventListener('click', (e) => {
        if (!cur) return;
        if (!e.isTrusted && /own|mixed/.test(scriptMade(new Error().stack))) return;
        if (typeof e.pointerId !== 'number') return;
        const t = now();
        if (e.pointerId !== -1) {
            const label = e.isTrusted && e.target && e.target.closest ? e.target.closest('label') : null;
            labelControl = label ? label.control : null;
            labelT = t;
            return;
        }
        // pointerId -1: the click came from code (element.click()) or from an
        // activation without a pointer. Not counted: the click a label passes
        // on, and the browser's own click for a key (Enter, Space, and the
        // arrow keys that move the choice in a group of radio buttons).
        if (e.isTrusted && labelControl && e.target === labelControl && t - labelT < 100) return;
        if (t - activateT > 300) cur.c.clk_nopt++;
    }, true);

    // ---------- keyboard and text ----------
    document.addEventListener('keydown', (e) => {
        if (!cur) return;
        if (!e.isTrusted) { scriptMade(new Error().stack); return; }
        const c = cur.c;
        firstAction(); gesture(e); unfocusedAction();
        c.keys++;
        if (isTextField(e.target)) bump(cur.keysIn, e.target, 1);
        const t = now(), key = e.key || '';
        lastKeydownT = t;
        if (key === 'Backspace' || key === 'Delete') c.bksp++;
        if (key === 'Shift') shiftDownT = t;
        if (key === 'Dead' || key === 'Compose') deadT = t;
        // Keys for which the browser itself clicks what has the focus: Enter and
        // Space, and the arrow keys in a group of radio buttons.
        if (key === 'Enter' || key === ' ' || key.indexOf('Arrow') === 0) activateT = t;
        lastKeyName = key;
        if (!env.mob && key.length === 1) {
            if (!e.code) c.kcode0++;
            if (key !== key.toLowerCase() && key === key.toUpperCase()) {
                const caps = e.getModifierState && e.getModifierState('CapsLock');
                c.kcap++;
                if (!e.shiftKey && !caps) c.kcap_ns++;                // the event says Shift was not held
                if (!(shiftDownT > shiftUpT) && !caps) c.kcap_nosk++; // no Shift key press before it
            }
        }
        if (e.repeat || /^(Shift|Control|Alt|Meta|CapsLock|Process|Unidentified|Dead)$/.test(key)) return;
        // Where do long pauses fall? People pause before starting a new word; humanised bots pause on random keys.
        if (lastKeyT) {
            const gap = t - lastKeyT, printable = key.length === 1 && /\S/.test(key);
            const wordStart = startsWord(prevKey, e.target === prevKeyEl);
            if (printable) {
                c.kwc++;
                if (wordStart) c.kws++;
                if (gap >= 500 && gap <= 5000) { c.klp++; if (wordStart) c.klp_ws++; }
            }
            if (gap < 1000) { // within a typing burst
                cur.keyIntervals.push(gap);
                c.kn++;
                if (gap < 12) c.kfast++;
                // people pause longer before starting a new word than between letters of a word
                if (printable) {
                    if (wordStart) cur.ivBoundary.push(gap);
                    else if (prevKey.length === 1) cur.ivWord.push(gap);
                }
            }
        }
        prevKey = key;
        prevKeyEl = e.target;
        lastKeyT = t;
        keyDown[e.code || e.key] = t;
    }, true);
    document.addEventListener('keyup', (e) => {
        const k = e.code || e.key, t = keyDown[k];
        if (e.key === 'Shift') shiftUpT = now();
        if (e.key === 'Enter' || e.key === ' ') activateT = now(); // Space activates on release
        // phones fire keydown and keyup together, so the dwell check is desktop-only
        if (t !== undefined) { if (cur && !env.mob && now() - t < 8) cur.c.kdwf++; delete keyDown[k]; }
    }, true);
    document.addEventListener('compositionstart', () => { composing = true; }, true);
    document.addEventListener('compositionend', () => { composing = false; lastKeydownT = now(); }, true);

    document.addEventListener('input', (e) => {
        if (!cur) return;
        const el = e.target;
        if (!e.isTrusted) { if (scriptMade(new Error().stack) === 'own' && !reacting) ownWrite(el); return; }
        const c = cur.c;
        // The input (and change) the browser itself fires for a script's
        // click() on a radio button or a checkbox is trusted, and not a
        // person's: no first action, and the item stays untouched.
        if (!reacting) { firstAction(); touch(el); }
        if (!isTextField(el)) return;
        const it = e.inputType || '', len = (e.data || '').length;
        if (!env.mob && it === 'insertText' && len === 1 && /[^\x00-\x7F]/.test(e.data) && !composing && lastKeyName !== e.data
            && now() - deadT > 3000 && !/^(Alt|AltGraph|Process|Unidentified|Dead|Compose|[0-9])$/.test(lastKeyName)) c.ins_na++;
        if (it === 'insertText' && !composing && now() - lastKeydownT > 150) { c.ins_nokey++; c.ins_nokey_ch += len; unfocusedAction(); }
        else if (it === 'insertReplacementText') c.ins_repl++;
        else if (it === '') c.ins_other++;
    }, true);
    document.addEventListener('change', (e) => {
        if (!cur) return;
        if (!e.isTrusted) { if (scriptMade(new Error().stack) === 'own' && !reacting) ownWrite(e.target); return; }
        if (!reacting) touch(e.target);
    }, true);

    // ---------- focus, scroll ----------
    // (refocusT, when the window or the tab came back, is set where leaving
    // the page is counted, below.)
    // Not counted: a field focused as the page appears, by the window coming
    // back (the browser re-focuses the last field), or by the page's own code.
    document.addEventListener('focusin', (e) => {
        if (!cur) return;
        const t = now();
        if (t - cur.enteredAt > 500 && t - lastGestureT > 1000 && t - refocusT > 500 && t - ownFocusT > 100
            && e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) cur.c.foc_nogest++;
    }, true);
    ['wheel', 'touchmove', 'keydown', 'pointerdown'].forEach((ev) => {
        window.addEventListener(ev, () => { lastScrollGestureT = now(); }, { capture: true, passive: true });
    });
    window.addEventListener('scroll', () => {
        if (!cur) return;
        const t = now();
        cur.c.scr++;
        // Not counted: as the page appears, and while the page's own scroll to
        // an item runs (ownScrollT; a smooth scroll takes up to a second).
        if (t - lastScrollGestureT > 1000 && t - cur.enteredAt > 1000 && t - ownScrollT > 1500) cur.c.scr_nogest++;
    }, { capture: true, passive: true });

    // ---------- copy / paste ----------
    document.addEventListener('copy', () => {
        if (!cur) return;
        const c = cur.c, ae = document.activeElement;
        c.copy++;
        // A copy made in a field is not one of question text. An email or a
        // number field does not say how much of it is selected.
        if (isTextField(ae)) c.copy_ch += typeof ae.selectionStart === 'number' ? Math.abs(ae.selectionEnd - ae.selectionStart) : 0;
        else { c.copy_q++; c.copy_ch += String(window.getSelection ? window.getSelection() : '').length; }
    }, true);
    document.addEventListener('cut', () => { if (cur) cur.c.cut++; }, true);
    document.addEventListener('paste', (e) => {
        if (!cur) return;
        let pl = 0;
        try { pl = ((e.clipboardData || window.clipboardData).getData('text') || '').length; } catch (err) { /* no access */ }
        cur.c.paste++;
        cur.c.paste_ch += pl;
        bump(cur.pastedIn, e.target, pl);
        lastKeydownT = now(); // the following input event belongs to the paste
        touch(e.target);
    }, true);
    document.addEventListener('contextmenu', () => { if (cur) cur.c.ctx++; }, true);

    // ---------- leaving the page, resizing ----------
    document.addEventListener('visibilitychange', () => {
        const hidden = document.visibilityState === 'hidden';
        if (!hidden) refocusT = now();
        if (!cur) return;
        if (hidden) { cur.c.hid_n++; hiddenSince = now(); }
        else if (hiddenSince !== null) { cur.c.hid_ms += now() - hiddenSince; hiddenSince = null; }
    });
    window.addEventListener('blur', (e) => {
        if (e.target !== window) return;
        setTimeout(() => {
            const ae = document.activeElement;
            // Not away: the focus moved into an embedded frame, or is back
            // already (a dialog of the page's: its blur and focus arrive
            // together, before this timer).
            if (!cur || document.hasFocus() || (ae && ae.tagName === 'IFRAME')) return;
            if (blurSince === null) { cur.c.blur_n++; blurSince = now(); }
        }, 0);
    });
    window.addEventListener('focus', (e) => {
        if (e.target !== window) return;
        refocusT = now();
        if (cur && blurSince !== null) { cur.c.blur_ms += now() - blurSince; blurSince = null; }
    }, true);
    window.addEventListener('resize', () => {
        if (cur) {
            cur.c.rsz++;
            // A window's panels and bars: not on a phone or tablet, where the
            // browser's own toolbar and the keyboard resize the page.
            if (!env.mob && outerWidth === lastOW && outerHeight === lastOH) {
                if (innerWidth < lastIW - 50 && innerHeight === lastIH) cur.c.rsz_side++; // side panel opened
                if (innerHeight < lastIH - 20 && innerWidth === lastIW) cur.c.rsz_bar++;  // info bar opened
            }
        }
        lastIW = innerWidth; lastIH = innerHeight; lastOW = outerWidth; lastOH = outerHeight;
    });

    // ---------- the record ----------
    function record(scope, final) {
        const p = pages.filter((q) => q.scope === scope)[0];
        if (!p) return null;
        const t = now(), shown = p === cur;
        if (final) {
            scanning = p;
            scanGlobals(); fullScan();
            scanning = null;
        }
        const live = Object.assign(blankRecord(), p.c, env);
        live.t = Math.round(p.c.t + (shown ? t - p.enteredAt : 0));
        live.hid_ms = Math.round(p.c.hid_ms + (shown && hiddenSince !== null ? t - hiddenSince : 0));
        live.blur_ms = Math.round(p.c.blur_ms + (shown && blurSince !== null ? t - blurSince : 0));
        live.kcv = variation(p.keyIntervals);
        // (-1 also when the keys within words came with no time between them:
        // nothing to divide by, and no number a record can hold)
        const withinWord = p.ivBoundary.length >= 5 && p.ivWord.length >= 20 ? median(p.ivWord) : 0;
        live.kwb = withinWord > 0 ? Math.round(median(p.ivBoundary) / withinWord * 10) / 10 : -1;
        let txt = 0, prog = 0, fld = 0;
        const seen = {};
        controls(p.scope).forEach((el) => {
            // an input that appeared after the page did starts from what it holds now
            if (!(el.name in p.initialVal)) p.initialVal[el.name] = valueOf(el.name);
            if (isTypedField(el)) {
                // What was written into the field on this visit: not the
                // stored answer a page comes back with, nor a default.
                const fresh = newChars(valueOf(el.name), p.initialVal[el.name]);
                txt += fresh;
                // a text answer of 10+ new characters that mostly did not come from key presses or a paste into it
                if (fresh >= 10 && countIn(p.keysIn, el) + countIn(p.pastedIn, el) < 0.5 * fresh) fld++;
            }
            if (!seen[el.name]) {
                seen[el.name] = 1;
                if (valueOf(el.name) !== p.initialVal[el.name] && p.touched.indexOf(group(el)) < 0) prog++; // answered, never touched
            }
        });
        live.txt_ch = txt;
        live.fld_nokey = fld;
        live.prog = prog;
        if (final && !env.mob && (window.outerWidth > screen.width + 20 || window.outerHeight > screen.height + 20)) live.winscr = 1;
        live.glob = Object.keys(tools).sort().join(',').slice(0, 200);
        live.ext = Object.keys(extHits).sort().join(',').slice(0, 100);
        return mergeRecords(p.base, live);
    }

    return { enter, leave, record };
}

// --- The classic engine: one page per document -----------------------------
// The page's record is written into its data_quality input every second and
// when the form is submitted. A page rendered again after a validation error
// comes back with its record in the input, and counting goes on from there.
//
// Returns null on a page without the item, else { start }: a row whose showif
// has switched it off (liveFields) records nothing, and survey.js asks again
// once the page's showifs have run (Survey.update).
export function initDataQuality(doc = document) {
    if (!doc.querySelector(FIELD_SELECTOR)) return null;
    let recorder = null;
    const flush = (final) => {
        const fields = liveFields(doc);
        if (!fields.length) return;
        const json = JSON.stringify(recorder.record(doc, final));
        fields.forEach((field) => { field.value = json; });
    };
    const start = () => {
        if (recorder) return;
        const fields = liveFields(doc);
        if (!fields.length) return;
        recorder = createRecorder();
        recorder.enter(doc, parseRecord(fields[0].value));
        flush(false);
        setInterval(() => flush(false), 1000);
        doc.addEventListener('submit', () => flush(true), true);
        doc.addEventListener('click', (e) => {
            if (e.target && e.target.closest && e.target.closest('button[type="submit"], input[type="submit"]')) flush(true);
        }, true);
    };
    start();
    return { start };
}
