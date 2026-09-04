// rank item (form_v2): Qualtrics-style drag-and-drop rank order.
//
// Server markup (Rank_Item::render_input):
//   <div class="fmr-rank" data-fmr-rank [data-rank-touch="1"]>
//     <ol class="fmr-rank-list">
//       <li class="fmr-rank-item" data-value="K" tabindex="0" aria-describedby=…>
//         .fmr-rank-pos  .fmr-rank-handle  .fmr-rank-label
//         .fmr-rank-btns > button.fmr-rank-up + button.fmr-rank-down
//         <input type="hidden" name="q[]" value="K">      ← '' until touched in touch mode
//       </li> …
//     </ol>
//     <span id="itemN-rank-hint" class="visually-hidden">   keyboard hint (aria-describedby)
//     <span class="fmr-rank-status visually-hidden" aria-live="polite">
//   </div>
//
// DOM order of the rows IS the answer: each hidden `q[]` input travels with
// its row, so collectPayload posts the keys in ranked order with no
// serialising here. Three ways to reorder — pointer drag (mouse at once,
// touch via the handle or press-and-hold on the row so page scrolling still
// works), the ▲/▼ buttons, and the keyboard (Arrow Up/Down, Home/End on a
// focused row). A plain tap on a row counts as "I looked at this" — that is
// what `touch` mode (data-rank-touch) waits for before the hidden inputs are
// filled; until then the generic required gate in validation/feedback.js
// sees an unanswered item.
//
// Every interaction dispatches bubbling `change` + `input` on the first hidden
// input, so the answered-timestamp stamp, Alpine's showif sync, and the inline
// feedback clearer all fire exactly as they do for native inputs.

import { t } from '../lib/i18n.js';

const DRAG_THRESHOLD = 4;     // px of mouse movement before a press becomes a drag
const TOUCH_HOLD_MS = 180;    // press-and-hold on the row body before a touch drags
const TOUCH_SLOP = 8;         // touch movement during the hold that hands over to scrolling
const AUTOSCROLL_EDGE = 48;   // px from the viewport edge that scrolls the page while dragging
const AUTOSCROLL_STEP = 14;

export function initRank(root) {
    root.querySelectorAll('[data-fmr-rank]').forEach((box) => {
        if (box.dataset.fmrRankInit === '1') return;
        box.dataset.fmrRankInit = '1';
        new RankWidget(box);
    });
}

class RankWidget {
    constructor(box) {
        this.box = box;
        this.list = box.querySelector('.fmr-rank-list');
        this.status = box.querySelector('.fmr-rank-status');
        this.touched = box.classList.contains('fmr-rank-touched');
        this.press = null;   // pending pointer press {row, id, x, y, type, timer}
        this.drag = null;    // active drag {row, placeholder, offsetY, raf}

        const hint = box.querySelector('[id$="-rank-hint"]');
        if (hint) hint.textContent = t('rank.hint', 'Use the up and down arrow keys to move this option; Home and End move it to the top or bottom.');
        this.rows().forEach((row) => this.decorate(row));
        this.renumber();

        this.list.addEventListener('pointerdown', (e) => this.onPointerDown(e));
        this.list.addEventListener('click', (e) => this.onClick(e));
        this.list.addEventListener('keydown', (e) => this.onKeyDown(e));
        // Non-passive so preventDefault() can veto the page scroll while a
        // touch drag is in progress (pointer events alone can't).
        this.list.addEventListener('touchmove', (e) => { if (this.drag) e.preventDefault(); }, { passive: false });
        this.list.addEventListener('contextmenu', (e) => { if (this.press || this.drag) e.preventDefault(); });
        this.onMove = (e) => this.onPointerMove(e);
        this.onUp = (e) => this.onPointerUp(e);
    }

    rows() {
        return Array.from(this.list.querySelectorAll(':scope > .fmr-rank-item'));
    }

    decorate(row) {
        const up = row.querySelector('.fmr-rank-up');
        const down = row.querySelector('.fmr-rank-down');
        if (up) up.setAttribute('aria-label', t('rank.move_up', 'Move up'));
        if (down) down.setAttribute('aria-label', t('rank.move_down', 'Move down'));
    }

    labelOf(row) {
        const el = row.querySelector('.fmr-rank-label');
        return (el ? el.textContent : row.dataset.value || '').trim();
    }

    // Position badges, end-of-list button states and per-row a11y labels.
    renumber() {
        const rows = this.rows();
        rows.forEach((row, i) => {
            const pos = row.querySelector('.fmr-rank-pos');
            if (pos) pos.textContent = String(i + 1);
            const up = row.querySelector('.fmr-rank-up');
            const down = row.querySelector('.fmr-rank-down');
            if (up) up.disabled = i === 0;
            if (down) down.disabled = i === rows.length - 1;
            row.setAttribute('aria-label', t('rank.position', '%label, position %pos of %total', {
                label: this.labelOf(row), pos: i + 1, total: rows.length,
            }));
        });
    }

    // Record an interaction: fill the hidden inputs (touch mode leaves them
    // empty until now), then notify the form exactly like a native change.
    commit(movedRow) {
        this.rows().forEach((row) => {
            const inp = row.querySelector('input[type=hidden]');
            if (inp) inp.value = row.dataset.value || '';
        });
        this.renumber();
        this.touched = true;
        this.box.classList.add('fmr-rank-touched');
        const first = this.list.querySelector('.fmr-rank-item input[type=hidden]');
        if (first) {
            first.dispatchEvent(new Event('change', { bubbles: true }));
            first.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (movedRow && this.status) {
            const rows = this.rows();
            this.status.textContent = t('rank.moved', '%label moved to position %pos of %total', {
                label: this.labelOf(movedRow), pos: rows.indexOf(movedRow) + 1, total: rows.length,
            });
        }
    }

    moveRow(row, delta) {
        const rows = this.rows();
        const from = rows.indexOf(row);
        const to = Math.max(0, Math.min(rows.length - 1, from + delta));
        if (to !== from) {
            const ref = rows[to];
            if (to < from) this.list.insertBefore(row, ref);
            else this.list.insertBefore(row, ref.nextSibling);
        }
        this.commit(row);
        return to !== from;
    }

    onClick(e) {
        const btn = e.target.closest('.fmr-rank-up, .fmr-rank-down');
        if (!btn || btn.disabled) return;
        e.preventDefault();
        const row = btn.closest('.fmr-rank-item');
        if (!row) return;
        this.moveRow(row, btn.classList.contains('fmr-rank-up') ? -1 : 1);
        // A button that just became disabled drops focus on the body; keep the
        // pointer user's context on the row instead.
        if (btn.disabled) row.focus({ preventScroll: true });
    }

    onKeyDown(e) {
        const row = e.target.closest('.fmr-rank-item');
        if (!row || e.target !== row) return;
        const rows = this.rows();
        let delta = null;
        if (e.key === 'ArrowUp') delta = -1;
        else if (e.key === 'ArrowDown') delta = 1;
        else if (e.key === 'Home') delta = -rows.indexOf(row);
        else if (e.key === 'End') delta = rows.length - 1 - rows.indexOf(row);
        else return;
        // The key is ours: mark it handled (preventDefault) AND keep it from
        // bubbling. The solo controller listens for Arrow keys on `document` to
        // move between steps — without stopPropagation, reordering a row with
        // ArrowDown would also advance the page (and submit it on the last step).
        e.preventDefault();
        e.stopPropagation();
        this.moveRow(row, delta);
        row.focus({ preventScroll: true });
    }

    // --- pointer: tap, and drag ------------------------------------------------

    onPointerDown(e) {
        if (this.press || this.drag) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        const row = e.target.closest('.fmr-rank-item');
        if (!row || e.target.closest('button')) return;
        const onHandle = !!e.target.closest('.fmr-rank-handle');
        this.press = { row, id: e.pointerId, x: e.clientX, y: e.clientY, type: e.pointerType, timer: null };
        document.addEventListener('pointermove', this.onMove, { passive: false });
        document.addEventListener('pointerup', this.onUp);
        document.addEventListener('pointercancel', this.onUp);
        if (e.pointerType === 'mouse') {
            e.preventDefault();  // no text selection / native drag while pressing
            row.focus({ preventScroll: true });
        } else if (onHandle) {
            // The handle has touch-action:none, so the browser won't scroll: drag now.
            this.startDrag(row, e);
        } else {
            // Row body: hold to lift. Scrolling before the hold cancels the press.
            this.press.timer = setTimeout(() => {
                if (this.press && !this.drag) this.startDrag(row, this.press);
            }, TOUCH_HOLD_MS);
        }
    }

    onPointerMove(e) {
        if (this.drag) {
            if (e.pointerId !== this.drag.id) return;
            e.preventDefault();
            this.drag.y = e.clientY;
            this.positionDragged();
            this.reorderAround(e.clientY);
            this.autoscroll(e.clientY);
            return;
        }
        const p = this.press;
        if (!p || e.pointerId !== p.id) return;
        const dist = Math.hypot(e.clientX - p.x, e.clientY - p.y);
        if (p.type === 'mouse') {
            if (dist >= DRAG_THRESHOLD) this.startDrag(p.row, e);
        } else if (dist >= TOUCH_SLOP) {
            this.releasePress();  // the finger is scrolling the page
        }
    }

    onPointerUp(e) {
        if (this.drag) {
            if (e.pointerId === this.drag.id) this.endDrag();
            return;
        }
        const p = this.press;
        if (!p || e.pointerId !== p.id) return;
        this.releasePress();
        if (e.type === 'pointerup') {
            // A tap: no reorder, but it is the interaction `touch` mode waits for.
            p.row.focus({ preventScroll: true });
            this.commit(null);
        }
    }

    releasePress() {
        if (this.press && this.press.timer) clearTimeout(this.press.timer);
        this.press = null;
        document.removeEventListener('pointermove', this.onMove);
        document.removeEventListener('pointerup', this.onUp);
        document.removeEventListener('pointercancel', this.onUp);
    }

    // Lift the row out of the flow: it follows the pointer as a fixed-position
    // ghost while a same-height placeholder holds its slot in the list.
    startDrag(row, at) {
        const p = this.press;
        const rect = row.getBoundingClientRect();
        const placeholder = document.createElement('li');
        placeholder.className = 'fmr-rank-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.style.height = rect.height + 'px';
        row.parentNode.insertBefore(placeholder, row.nextSibling);
        this.drag = {
            row, placeholder, id: p ? p.id : at.pointerId,
            offsetY: at.clientY - rect.top, left: rect.left, width: rect.width, y: at.clientY,
        };
        if (p && p.timer) clearTimeout(p.timer);
        this.press = null;
        row.classList.add('fmr-rank-dragging');
        row.style.width = rect.width + 'px';
        row.style.left = rect.left + 'px';
        this.positionDragged();
        this.box.classList.add('fmr-rank-active');
        this.startedIndex = this.rows().indexOf(row);
    }

    positionDragged() {
        const d = this.drag;
        d.row.style.top = (d.y - d.offsetY) + 'px';
    }

    // Slide the placeholder to the slot whose row midpoint the pointer crossed.
    reorderAround(pointerY) {
        const d = this.drag;
        const others = this.rows().filter((r) => r !== d.row);
        let index = 0;
        for (const r of others) {
            const rect = r.getBoundingClientRect();
            if (pointerY > rect.top + rect.height / 2) index++;
        }
        const before = others[index] || null;
        if (d.placeholder.nextSibling === before || d.placeholder === before) return;
        const first = new Map(others.map((r) => [r, r.getBoundingClientRect().top]));
        this.list.insertBefore(d.placeholder, before);
        this.flip(others, first);
    }

    // FLIP: rows that changed slot glide from their old position instead of jumping.
    flip(rowsToAnimate, firstTops) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        rowsToAnimate.forEach((r) => {
            const dy = firstTops.get(r) - r.getBoundingClientRect().top;
            if (!dy) return;
            r.style.transition = 'none';
            r.style.transform = `translateY(${dy}px)`;
            r.getBoundingClientRect();  // flush so the transition below has a start frame
            r.style.transition = 'transform 120ms ease';
            r.style.transform = '';
            r.addEventListener('transitionend', () => { r.style.transition = ''; }, { once: true });
        });
    }

    autoscroll(pointerY) {
        const h = window.innerHeight;
        let dy = 0;
        if (pointerY < AUTOSCROLL_EDGE) dy = -AUTOSCROLL_STEP;
        else if (pointerY > h - AUTOSCROLL_EDGE) dy = AUTOSCROLL_STEP;
        if (dy) {
            window.scrollBy(0, dy);
            // The list moved under a still pointer: keep the slot in step.
            this.reorderAround(pointerY);
        }
    }

    endDrag() {
        const d = this.drag;
        this.drag = null;
        this.releasePress();
        this.list.insertBefore(d.row, d.placeholder);
        d.placeholder.remove();
        d.row.classList.remove('fmr-rank-dragging');
        d.row.style.top = d.row.style.left = d.row.style.width = '';
        this.box.classList.remove('fmr-rank-active');
        d.row.focus({ preventScroll: true });
        const moved = this.rows().indexOf(d.row) !== this.startedIndex;
        this.commit(moved ? d.row : null);
    }
}
