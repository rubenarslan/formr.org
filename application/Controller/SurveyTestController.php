<?php

/**
 * Token-gated survey preview / "Test Survey" renderer.
 *
 * Renders a survey as a participant TEST_RUN, but on a path OUTSIDE /admin/
 * (route 'survey-test', see setup.php) so it carries neither the admin CSP
 * (Site::inAdminArea() is false here) nor the /admin/-scoped admin session
 * cookies. The participant survey renderer legitimately needs new Function()
 * for live showif and emits inline bootstrap scripts, which the admin CSP
 * would block. Moving the render here keeps the admin CSP strict everywhere
 * on /admin/ while letting survey testing work — and an XSS in the rendered
 * (participant-authored) survey can't ride the admin session, because the
 * admin cookies are scoped to /admin/ and never sent to /survey-test/.
 *
 * Authorisation travels in a short-lived Halite token minted by
 * AdminSurveyController::accessAction (which runs under /admin/ with the admin
 * session). This route never reads the admin session — it can't, wrong path.
 */
class SurveyTestController extends Controller {

    const TOKEN_TTL = 900; // 15 min — covers a test session, bounds replay
    const TOKEN_GLUE = '|';

    /**
     * Mint a URL-safe preview token for a survey owned by $userId. Halite
     * ciphertext is base64url, so it is safe as a path segment.
     */
    public static function mintToken($studyName, $userId, $ttl = self::TOKEN_TTL) {
        return Crypto::encrypt(array($studyName, (int) $userId, time() + (int) $ttl), self::TOKEN_GLUE);
    }

    public function indexAction($token = null, $page = null) {
        $parsed = $this->verifyToken($token);
        if ($parsed === null) {
            return; // verifyToken already emitted the error page
        }
        list($studyName, $userId, $expired) = $parsed;

        // Paged surveys (use_paging) navigate by a URL page segment:
        // /survey-test/<token>/<page>. Mirror RunController — surface that
        // segment as the pageNo request global so PagedSpreadsheetRenderer
        // renders the page instead of redirecting to add it. Without this the
        // renderer always redirects (no pageNo present), and the redirect below
        // bounces back here page-less, looping forever.
        if ($page !== null && (int) $page > 0) {
            Request::setGlobals('pageNo', (int) $page);
        }

        $user = new User($userId);
        if (!$user->id) {
            return formr_error(403, 'Forbidden', 'Unknown user for this preview link.');
        }
        $study = SurveyStudy::loadByUserAndName($user, $studyName);
        if (empty($study) || !$study->valid) {
            return formr_error(404, 'Not found', 'This survey preview is no longer available.');
        }

        // An expired token may only CONTINUE a test that is already in
        // flight in this browser's /survey-test/ session — never start a
        // new one. TOKEN_TTL bounds how long a (possibly leaked) link can
        // mint fresh previews, but filling out a survey legitimately takes
        // longer than any sane TTL: without this carve-out, the first
        // "Next"/"Finish" POST after the 15-minute mark got the 410 below
        // and silently discarded that page's answers (the pre-1.3.0
        // admin-session preview never expired mid-test).
        if ($expired && !$this->hasLiveTestSession($study)) {
            return formr_error(410, 'Link expired', 'This survey preview link has expired. Open the survey again and click "Test Survey".');
        }

        // Seed the test data the TEST_RUN exec reads (mirror of
        // AdminSurveyController::accessAction), in THIS request's own session
        // (cookie path /survey-test/, see determine_session_context()).
        //
        // Seed ONLY when there is no live test session for THIS study yet.
        // Run::testStudy() persists the created unit_session_id back into this
        // same array so later requests (the "Next" POSTs, which all hit this
        // one action) can resume and advance it. Re-seeding unconditionally
        // would drop unit_session_id every request, so every "Next" would spawn
        // a fresh unit session stuck on page 1 — and, when execute() answers a
        // unit-session creation with a PRG redirect, loop forever. A token for
        // a different study (or after the test finished and testStudy() cleared
        // the data) re-seeds as expected.
        if (!$this->hasLiveTestSession($study)) {
            Session::set('test_study_data_' . (int) $study->id, array(
                'study_id' => $study->id,
                'study_name' => $study->name,
                'unit_id' => $study->id,
                'data' => $study->getItems('id, name, type'),
            ));
        }

        $testRun = new Run(Run::TEST_RUN);
        // The token carries the study identity — key the exec's session slot
        // by it, so previews of different studies in different tabs each keep
        // their own in-flight state (review 2026-07, item 17).
        $testRun->test_study_id = (int) $study->id;
        $run_vars = $testRun->exec($user);
        if (!$run_vars) {
            return formr_error(500, 'Invalid Execution', 'The execution generated no output');
        }
        // Where TEST_RUN URLs are rewritten to: the token preview path. Both
        // run_url(TEST_RUN) and this end in a slash, so a page segment appended
        // by the run engine (run_url(TEST_RUN, N) = …/run/formr-test-run/N/)
        // maps cleanly to …/survey-test/<token>/N/.
        $surveyTestUrl = site_url('survey-test/' . $token);

        if (!empty($run_vars['redirect'])) {
            // Translate the run engine's redirect — a TEST_RUN run_url that, for
            // paged surveys, carries the next page as a path segment — into the
            // matching /survey-test/ URL so the page survives the PRG hop. The
            // old code dropped the page (always redirected to the bare token
            // URL), which is why paged-survey previews looped endlessly.
            $target = str_replace(run_url(Run::TEST_RUN), $surveyTestUrl, $run_vars['redirect']);
            if ($target === $run_vars['redirect']) {
                $target = $surveyTestUrl; // not a TEST_RUN url (defensive)
            }
            return $this->request->redirect($target);
        }

        $run_vars['bodyClass'] = 'fmr-run';
        $assets = Config::get('assets.frontend');
        $run_vars['js'] = array(array_val($assets, 'js', array()));
        // Point the form / continue links at this preview URL, not the raw
        // TEST_RUN run URL, so multi-page tests stay on /survey-test/.
        $run_vars['run_content'] = str_replace(
            run_url(Run::TEST_RUN), $surveyTestUrl, $run_vars['run_content']
        );

        $this->setView('run/index', $run_vars);
        return $this->sendResponse();
    }

    /**
     * Is a test of $study already in flight in THIS request's session?
     * Run::testStudy() keeps the live test (incl. its unit_session_id)
     * under the per-study 'test_study_data_<id>' slot and deletes it when
     * the test finishes.
     */
    private function hasLiveTestSession($study) {
        $existing = Session::get('test_study_data_' . (int) $study->id);
        return is_array($existing) && (int) array_val($existing, 'study_id') === (int) $study->id;
    }

    /**
     * Decrypt + validate the preview token. Emits a 403 error page and
     * returns null on a forged or malformed token; otherwise
     * array($name, $userId, $expired). Expiry is deliberately NOT fatal
     * here — indexAction still honours an expired-but-authentic token when
     * it merely continues a test session that is already in flight.
     */
    private function verifyToken($token) {
        $plain = $token ? Crypto::decrypt($token) : null;
        if ($plain === null) {
            formr_error(403, 'Invalid link', 'This survey preview link is invalid.');
            return null;
        }
        $parts = explode(self::TOKEN_GLUE, $plain, 3);
        if (count($parts) !== 3) {
            formr_error(403, 'Invalid link', 'This survey preview link is malformed.');
            return null;
        }
        list($studyName, $userId, $expires) = $parts;
        return array($studyName, (int) $userId, (int) $expires < time());
    }
}
