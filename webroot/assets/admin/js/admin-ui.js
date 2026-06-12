// Cross-page admin UI behaviour, loaded via <script src> (CSP 'self', no
// nonce). Vanilla + delegated so it is independent of jQuery load order and
// of when the elements are parsed (loaded from the header, see admin/header.php).
(function () {
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }

        // Delegated confirm for [data-confirm], replacing inline
        // onclick="return confirm(...)" handlers that a nonce-based CSP blocks.
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }

        // Bare-"#" anchors are JS-handled buttons (formerly javascript:void(0),
        // which CSP blocks as a script-src navigation). Suppress the default
        // jump-to-top/#-in-URL; their real handlers still run (no stopPropagation).
        var a = e.target.closest('a');
        if (a && a.getAttribute('href') === '#') {
            e.preventDefault();
        }
    }, false);
})();
