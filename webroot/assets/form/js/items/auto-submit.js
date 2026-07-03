// v2 port of v1's Submit_Item `type_options` behaviours (common/js/survey.js
// initializeAutoSubmit / initializeAutoSubmitOnCompletion). The server renders
// the submit item's own <button> inside the page (FormRenderer's $hasSubmit
// nav path), carrying the v1 classes/attributes:
//
//   - `submit_automatically_after_timeout` + data-timeout (ms):
//       t > 0 → auto-submit the page after t ms (fuse bar counts down)
//       t < 0 → button starts disabled, enables after |t| ms (fuse counts down)
//   - `submit_on_all_answered` → submit as soon as every visible, answerable
//     item on the page has an answer.
//
// v1 armed on window load (one page per document); v2 arms when a page becomes
// current and disarms when the participant leaves it, so timed pages measure
// exposure to THAT page, not document age.
export function initAutoSubmit({ root, pages, getCurrentIndex, submitPage }) {
    let timers = [];
    let allAnsweredArmed = false;
    let checkTimer = null;
    const clearTimers = () => {
        timers.forEach(clearTimeout);
        timers = [];
        allAnsweredArmed = false;
        if (checkTimer) { clearTimeout(checkTimer); checkTimer = null; }
    };

    const addFuse = (btn, ms) => {
        let box = btn.querySelector('.submit_fuse_box');
        if (!box) {
            box = document.createElement('div');
            box.className = 'submit_fuse_box';
            box.appendChild(document.createElement('div')).className = 'submit_fuse';
            btn.appendChild(box);
        }
        const fuse = box.querySelector('.submit_fuse');
        fuse.style.transition = 'none';
        fuse.style.width = '100%';
        // Two frames so the 100% width paints before the transition starts.
        requestAnimationFrame(() => requestAnimationFrame(() => {
            fuse.style.transition = `width ${ms}ms linear`;
            fuse.style.width = '0';
        }));
    };

    // "Answered" mirrors v1's interaction tracking: the change listener in
    // main.js stamps `.item_answered` on first commit; value-presence covers
    // prefills and back-navigation where no fresh change event fired.
    const groupAnswered = (group) => {
        const stamp = group.querySelector('.item_answered');
        if (stamp && stamp.value) return true;
        const inputs = group.querySelectorAll('input[name], select[name], textarea[name]');
        for (const inp of inputs) {
            if (!inp.name || inp.name.startsWith('_item_views')) continue;
            if (inp.type === 'hidden') continue; // checkbox partners, plumbing
            if (inp.type === 'checkbox' || inp.type === 'radio') {
                if (inp.checked) return true;
            } else if (inp.value != null && String(inp.value).trim() !== '') {
                return true;
            }
        }
        return false;
    };

    // Same exclusion set as v1's completion check: notes, iframes, blocks and
    // the submit item itself don't need answers; showif-hidden items don't
    // block (their inputs are disabled and they may never appear).
    const answerableGroups = (page) => Array.from(page.querySelectorAll('.form-group'))
        .filter((g) => !g.classList.contains('hidden')
            && !g.hasAttribute('data-fmr-hidden')
            && !g.classList.contains('item-note')
            && !g.classList.contains('item-note_iframe')
            && !g.classList.contains('item-block')
            && !g.classList.contains('item-submit')
            && g.querySelector('input[name], select[name], textarea[name]'));

    const checkAllAnswered = () => {
        const page = pages[getCurrentIndex()];
        if (!page || !allAnsweredArmed) return;
        const groups = answerableGroups(page);
        if (groups.length > 0 && groups.every(groupAnswered)) {
            allAnsweredArmed = false; // one-shot: submitPage guards re-entry too
            submitPage();
        }
    };

    const arm = (page) => {
        clearTimers();
        if (!page) return;
        page.querySelectorAll('button.submit_automatically_after_timeout[data-timeout]').forEach((btn) => {
            const t = Number(btn.dataset.timeout);
            if (!t || isNaN(t)) return;
            if (t < 0) {
                // Delayed enable: participant must stay on the page |t| ms
                // before they may advance.
                btn.disabled = true;
                addFuse(btn, -t);
                timers.push(setTimeout(() => { btn.disabled = false; }, -t));
            } else {
                addFuse(btn, t);
                timers.push(setTimeout(() => { submitPage(); }, t));
            }
        });
        if (page.querySelector('button.submit_on_all_answered')) {
            allAnsweredArmed = true;
            // The page may already be fully answered (back-nav, prefills).
            checkAllAnswered();
        }
    };

    ['input', 'change'].forEach((evt) => {
        root.addEventListener(evt, () => {
            if (!allAnsweredArmed) return;
            if (checkTimer) clearTimeout(checkTimer);
            checkTimer = setTimeout(checkAllAnswered, 250);
        });
    });

    document.addEventListener('fmr:pagechange', () => arm(pages[getCurrentIndex()]));
    // Initial page: showPage(0) at init doesn't dispatch fmr:pagechange
    // (index didn't change), so arm explicitly.
    arm(pages[getCurrentIndex()]);
}
