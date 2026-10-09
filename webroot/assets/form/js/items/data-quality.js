// data_quality item: the page's record of automation and carelessness
// indicators (the checks are in common/js/data-quality.js, shared with the
// classic engine).
//
// Server (DataQuality_Item) renders `<input type="hidden" data-fmr-dq>` on
// each page that is to be recorded. A form without one gets the inert object
// below: no recorder, no listeners. With one, the recorder is created when a
// page that has the input is first shown — a consent page earlier in the same
// form records nothing — and it counts per page:
//
//   enter(pageEl)  main.js showPage: this page is on screen now. A page
//                  without the input ends the recording until one with it is
//                  shown again.
//   write(pageEl)  main.js collectPayload: put the page's record into its
//                  input, so it goes out with that page's answers (the page
//                  submit, an autosave of the page, a queued submit). Written
//                  with a plain assignment: no event, so nothing takes it for
//                  a change to autosave, and never on a timer.
//
// A page shown again in this document (Previous) keeps counting into the same
// record. After a reload the input holds the record stored before
// (FormRenderer renders stored answers), and this document's counts are added
// to it.
//
// A data_quality row with a showif is in use only while the showif shows it:
// hidden, its input is disabled (liveFields), and the page counts as one
// without the input. main.js calls enter() again when a showif of the page on
// screen has toggled, so recording starts and stops with the row.
import { createRecorder, parseRecord, liveFields, FIELD_SELECTOR } from '../../../common/js/data-quality.js';

const INERT = { enter() {}, write() {}, names: [] };

export function initDataQuality(root) {
    const fields = root.querySelectorAll(FIELD_SELECTOR);
    if (!fields.length) return INERT;
    const fieldsOf = (pageEl) => (pageEl ? liveFields(pageEl) : []);
    let recorder = null;
    return {
        enter(pageEl) {
            const own = fieldsOf(pageEl);
            if (!own.length) {
                if (recorder) recorder.leave();
                return;
            }
            if (!recorder) recorder = createRecorder();
            recorder.enter(pageEl, parseRecord(own[0].value));
        },
        write(pageEl) {
            const record = recorder ? recorder.record(pageEl, true) : null;
            if (!record) return;
            const json = JSON.stringify(record);
            fieldsOf(pageEl).forEach((field) => { field.value = json; });
        },
        // The inputs' names: their records are no answers for R to compute
        // with (main.js collectAllAnswers).
        names: Array.from(fields).map((field) => field.name),
    };
}
