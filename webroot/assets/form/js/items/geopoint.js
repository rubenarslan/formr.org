// Geopoint item: wire navigator.geolocation to the .geolocator button. v1 did
// this via webshim + jQuery; v2 has neither dep, so a bare addEventListener.
//
// Robustness (esp. Brave/mobile, where geolocation is often blocked or hangs):
//  - pass a `timeout` so getCurrentPosition can't hang forever with no GPS/
//    permission (the old call had none → "nothing happens");
//  - on denial/timeout/unavailable, surface a clear hint and leave the field
//    editable + focused for manual entry, instead of a silent no-op;
//  - show a transient "locating…" state on the button so the tap registers.

const flatStringifyGeo = (pos) => JSON.stringify({
    timestamp: pos.timestamp,
    coords: {
        accuracy: pos.coords.accuracy,
        altitude: pos.coords.altitude,
        altitudeAccuracy: pos.coords.altitudeAccuracy,
        heading: pos.coords.heading,
        latitude: pos.coords.latitude,
        longitude: pos.coords.longitude,
        speed: pos.coords.speed,
    },
});

// Don't hang on a device with no fix / a browser that silently withholds the
// prompt: cap the wait, allow a slightly stale fix, skip high-accuracy (faster
// and less likely to stall on mobile).
const GEO_OPTS = { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 };

export function initGeopoint(root) {
    if (!('geolocation' in navigator)) return;
    root.querySelectorAll('.geolocator').forEach((btn) => {
        // v1 wraps the button in <span class="input-group-btn hidden">; show
        // it now that JS is up.
        const wrapper = btn.closest('.input-group-btn');
        if (wrapper && wrapper.classList.contains('hidden')) {
            wrapper.classList.remove('hidden');
        }
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const controls = btn.closest('.controls');
            if (!controls) return;
            const hidden = controls.querySelector('input[type=hidden]');
            const visible = controls.querySelector('input[type=text]');

            // A geolocation request must come from a user gesture (this click) —
            // so make the field editable now and offer manual entry regardless
            // of how the lookup resolves.
            if (visible) {
                visible.placeholder = 'You can also type your location here';
                visible.removeAttribute('readonly');
            }
            const hint = (msg, isError) => {
                let el = controls.querySelector('.fmr-geo-hint');
                if (!el) {
                    el = document.createElement('div');
                    el.className = 'fmr-geo-hint help-block';
                    controls.appendChild(el);
                }
                el.textContent = msg || '';
                el.classList.toggle('text-danger', !!isError);
                el.style.display = msg ? '' : 'none';
            };

            const busy = (on) => {
                btn.disabled = on;
                btn.classList.toggle('is-locating', on);
            };

            busy(true);
            hint('Locating…', false);
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    busy(false);
                    hint('', false);
                    if (hidden) hidden.value = flatStringifyGeo(pos);
                    if (visible) {
                        visible.value = `lat:${pos.coords.latitude}/long:${pos.coords.longitude}`;
                        visible.setAttribute('readonly', 'readonly');
                    }
                    // clear any required-gating error now that it's answered
                    controls.querySelectorAll('.is-invalid').forEach((el) => {
                        el.classList.remove('is-invalid');
                        if (el.setCustomValidity) el.setCustomValidity('');
                    });
                    const grp = controls.closest('.form-group') || controls.parentElement;
                    if (grp) {
                        grp.classList.remove('is-invalid');
                        grp.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());
                    }
                },
                (err) => {
                    // Denied (1), unavailable (2) or timed out (3): don't leave the
                    // participant stuck. Keep the field editable, point them at it.
                    busy(false);
                    const denied = err && err.code === 1;
                    hint(denied
                        ? "Location access was blocked — please type your location instead."
                        : "Couldn't get your location automatically — please type it instead.", true);
                    if (visible) { try { visible.focus(); } catch (e) { /* noop */ } }
                },
                GEO_OPTS,
            );
        });
    });
}
