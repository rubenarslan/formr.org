# Review brief: the server side of form_v2

For a code review that reads the code and cannot drive a browser (a cloud
review). It says what to look at, which questions to ask, what earlier reviews
already found, and what a finding has to contain.

## Scope

Only PHP and SQL, and only what serves a v2 form:

- `application/Services/FormSubmissionService.php`, `FormSyncPayload.php`,
  `RCallService.php`, `PageSubmission.php`
- `application/Spreadsheet/FormRenderer.php`, `RAllowlistExtractor.php`
- `application/Controller/RunController.php`: the `form*Action`s,
  `formWriteContext`, `dispatchFormV2Preview`; `SurveyTestController.php`
- `application/Model/UnitSession.php`, `RunSession.php`, `RunUnit/Form.php`,
  and what they call

Out of scope: CSS, rendering and navigation in the browser, the `data_quality`
recorder (`webroot/assets/common/js/data-quality.js`, reviewed separately),
wording of documentation. A finding that can only be confirmed by clicking
through a form is out of scope too.

## The caller is not the form

The e2e suite sends what the form's own JavaScript sends. Assume a caller that
does not: someone with curl, two tabs, a replayed request, the queue daemon
acting in the same moment. The endpoints are `form-page-submit`, `form-sync`,
`form-save`, `form-render-page` and `form-bot-challenge`, and the same five
under `/survey-test/<token>/` for the admin's preview. For each:

1. **Who may call it?** A participant of another run or another session, a
   logged-out visitor, an ended or expired unit session, a survey that was
   switched back to v1, a preview token that is not the caller's, a run that
   no longer admits participants.
2. **What if it arrives twice, late, out of order, or together with another
   write?** Two tabs of one session; a `form-sync` uuid sent again, or sent
   twice at once; an autosave racing the final submit; the daemon expiring or
   moving the run session while a page is being applied; a request that dies
   half way. Name the lock, transaction or row that makes it safe, or the
   interleaving that breaks it.
3. **What does a hand-made payload store?** Names that are not items of the
   page or of the study; an array where the item is scalar and the reverse;
   `files[...]` for an item that takes no file; a `hidden[]` list naming
   required items; `final: true` before the last page; values for items the
   server assigns (`random`, `ip`, `server`, `browser`, `referrer`, a `hidden`
   item with an R `value`); `item_views` timestamps of any shape; `doc`, `seq`,
   `base` and `prev` chosen to pass `claimWrite`.
4. **Can a request make OpenCPU evaluate R the study's author did not write,
   or give author-written R more than the author meant?** The
   `survey_r_calls` allowlist, the answers overlay, `sticky` expansion, what
   the request builders scan to decide on API tokens, run secrets and other
   surveys' data.

## What earlier reviews found

The same kinds of mistake came back review after review. Each line names the
kind and commits that fixed an instance (`git show <hash>` shows its shape).
These instances are fixed. **Look for the same mistake where nobody has looked
yet**: another endpoint, the preview route, the multipart path beside the JSON
one, the queued path beside the live one.

- **A gate that one endpoint had and its sibling did not.** `cedad79f` (the
  v2 endpoints accepted a v1 study), `abc7b415` (`form-bot-challenge` without
  a run session or rate limit), `89a2abbf` (a write that names no unit session
  passed the stale-tab check), `90c599ab` (a closed run still took answers
  from an open form), `dfc733b2`, `4e39d943`, `fedd84ca`, `6375b63a`.
- **Trusting what the browser says about the form's state.** `50dbfb31`
  (a `hidden` list could withdraw every required item and complete the form
  unanswered), `882e87dc` (a posted value for a `random` item showed the other
  condition's materials), `5af19032`, `1e43924c`, `94dea815`.
- **Validation that one write path ran and another skipped.** `cedad79f`
  (autosave stored unvalidated values and marked the item saved), `43b03dba`
  (answers changed on the way in), `15237298` (an empty file input overwrote a
  stored upload; a spent `bot_check` token refused a legitimate resend).
- **Two things that had to happen together, and did not.** `0dc7f66e` and
  `0977b9e7` (`form-sync`: answers and the uuid ledger; a request that died
  part way swallowed its page), `8e4c3c8a` (a tab that had not seen the latest
  write overwrote it), `50c08cee` (a page stored in a unit session that had
  just expired), `93c09359` (the session lock held across an R call),
  `bf1d68eb`, `9f5bc04e` (a POST taken as a submission by a unit it did not
  name).
- **A participant's text read as code or as instructions.** `bd6abb07`
  (answers naming `formr_api_authenticate(` or a run secret made the request
  builder mint an owner token or load the secret), `9b3bbfc1` (answers pasted
  into R as literals), `3912b0dd`, `77c0500e` (slot names in SQL).
- **Limits that did not limit.** `619c5c6f` (`bot_check` difficulty grew as
  256^N), `401d0733` (a rate limit per IPv6 address instead of per /64),
  `8c758ea5`.

`documentation/agent_doc/form_v2_review_2026-09-23.md` is the note of one such
review, with its findings and what was done about each.

## Intended, so not a finding

Read `CLAUDE.md` ("Load-bearing / non-obvious", "form_v2 development notes")
and `documentation/agent_doc/plan_form_v2.md` first. Among what they describe
as decided:

- No CSRF tokens anywhere; authentication is the session cookie and same-site.
- A v2 showif is JavaScript and is evaluated in the browser only; the server
  honours a `hidden` claim only for items that have a showif.
- No R runs when a v2 form is rendered; values, labels and choice labels are
  resolved per page through `form-render-page`. `calculate` is unsupported.
- The browser decides which page is shown; the server never names a page.
- The offline queue drains from the page, not from the service worker.
- A request that names no `base` is neither checked nor counted by
  `claimWrite` (hand-made test requests rely on it). Say so if you can show
  that it lets a write through that the check exists to stop.
- A study's author may run any R: that is the product, not an injection.

## What a finding must contain

1. The exact request sequence, or the interleaving of two actors, that
   triggers it: method, endpoint, the fields that matter, and the state the
   session is in beforehand.
2. The path through the code, with `file:line` for each step that decides it.
3. What comes out wrong: the row stored, the reply sent, the R evaluated, or
   the lock left held.
4. Which sibling paths you checked for the same mistake, and what you found
   there.

It has to be reproducible without a browser, as a PHPUnit test or as a
`bin/*_smoke.php` script against MariaDB (the dev stack has both, and
OpenCPU). A suspicion that needs a real form to confirm belongs in a separate
list at the end, marked as unverified, not among the findings.
