// Cross-page admin UI behaviour, loaded via <script src> (CSP 'self', no
// nonce). Currently: delegated confirm for [data-confirm] elements, replacing
// inline onclick="return confirm(...)" handlers that a nonce-based CSP blocks.
// Vanilla + delegated so it is independent of jQuery load order.
(function () {
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, false);
})();
