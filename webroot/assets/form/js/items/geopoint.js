// Geopoint item: wire navigator.geolocation to the .geolocator button. v1
// did this via webshim + jQuery; v2 has neither dep, so a bare addEventListener.

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
            if (visible) {
                visible.placeholder = 'You can also enter your location manually';
                visible.removeAttribute('readonly');
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    if (hidden) hidden.value = flatStringifyGeo(pos);
                    if (visible) {
                        visible.value = `lat:${pos.coords.latitude}/long:${pos.coords.longitude}`;
                        visible.setAttribute('readonly', 'readonly');
                    }
                    controls.querySelectorAll('.is-invalid').forEach((el) => {
                        el.classList.remove('is-invalid');
                        el.setCustomValidity('');
                    });
                    controls.parentElement?.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());
                },
                () => {
                    // Permission denied or unavailable — visible field is now editable for manual entry.
                },
            );
        });
    });
}
