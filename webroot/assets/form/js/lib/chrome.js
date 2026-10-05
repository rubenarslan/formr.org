// The form's chrome that the default layout (main.js) and solo
// (solo/controller.js) both keep: the progress bar, and its refresh when a
// showif changes what is shown.

// A share of this survey, placed within the study's [from, to] window
// (add_percentage_points / displayed_percentage_maximum), so runs stitching
// several surveys into one 0–100 bar keep their coordinated ranges — parity
// with v1's stretch math.
export function progressPercent(root, fraction) {
    const from = Number(root.dataset.fmrProgressFrom) || 0;
    const to = Number(root.dataset.fmrProgressTo) || 100;
    return Math.round(from + fraction * (to - from));
}

export function setProgressBar(bar, pct) {
    if (!bar) return;
    bar.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', String(pct));
}

// `fn`, run at most once per frame however often it is asked for: one
// answer can toggle many showifs, and each refresh snapshots the form.
export function oncePerFrame(fn) {
    let queued = false;
    return () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            fn();
        });
    };
}
