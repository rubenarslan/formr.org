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

    const TOKEN_TTL = 3600; // 60 min — covers a test session, bounds replay
    const TOKEN_GLUE = '|';

    /**
     * Mint a URL-safe preview token for a survey owned by $userId. Halite
     * ciphertext is base64url, so it is safe as a path segment.
     */
    public static function mintToken($studyName, $userId, $ttl = self::TOKEN_TTL) {
        // The token is glue-joined plaintext under authenticated encryption,
        // so a glue character inside the study name would let the name
        // re-map the user id / expiry fields on the way back out. Names are
        // validated on creation and rename; refuse here too — by the same rule.
        if (!SurveyStudy::isValidName((string) $studyName)) {
            return null;
        }
        return Crypto::encrypt(array($studyName, (int) $userId, time() + (int) $ttl), self::TOKEN_GLUE);
    }

    public function indexAction($token = null, $page = null) {
        $parsed = $this->verifyToken($token);
        if ($parsed === null) {
            return; // verifyToken already emitted the error page
        }
        list($studyName, $userId, $expired, $expires) = $parsed;

        // /survey-test/<token>/form-page-submit etc.: a v2 form's own
        // endpoints, for the preview's test session (formV2Endpoint).
        if ($page !== null && isset(RunController::FORM_V2_PREVIEW_ACTIONS[$page])) {
            return $this->formV2Endpoint($token, (string) $page, $studyName, $userId, $expired, $expires);
        }

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
        // "Next"/"Finish" POST after the token's TTL got the 410 below
        // and silently discarded that page's answers (the pre-1.3.0
        // admin-session preview never expired mid-test).
        if ($expired && !$this->hasLiveTestSession($study, $expires)) {
            return formr_error(410, 'Link expired', 'This survey preview link has expired. Open the survey again and click "Test Survey".');
        }

        // Seed the test data the TEST_RUN exec reads (mirror of
        // AdminSurveyController::accessAction), in THIS request's own session
        // (cookie path /survey-test/, see determine_session_context()).
        //
        // Seed when there is no live test session for THIS study and THIS
        // token. Run::testStudy() persists the created unit_session_id back
        // into this same array so later requests (the "Next" POSTs and page
        // GETs, which all hit this one action with the SAME token) can resume
        // and advance it. Re-seeding unconditionally would drop
        // unit_session_id every request, so every "Next" would spawn a fresh
        // unit session stuck on page 1 — and, when execute() answers a
        // unit-session creation with a PRG redirect, loop forever.
        //
        // A NEWER token — the admin clicked "Test Survey" again — starts a
        // fresh test instead of resuming the old one, as the pre-1.3.0
        // admin-session preview did (it re-seeded on every click; the
        // resume was a side effect of moving the seed into this action).
        // Resuming is what made a corrected item table look broken: the old
        // unit session kept the showif verdicts survey_items_display had
        // cached under the previous showif, so the item stayed hidden until
        // the survey was deleted and recreated. The token's expiry doubles as
        // its mint stamp. A token for a different study, or one arriving
        // after the test finished and testStudy() cleared the slot, re-seeds
        // as before.
        if (!$this->hasLiveTestSession($study, $expires)) {
            Session::set('test_study_data_' . (int) $study->id, array(
                'study_id' => $study->id,
                'study_name' => $study->name,
                'unit_id' => $study->id,
                'token_expires' => $expires,
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
        // TEST_RUN run URL, so multi-page tests stay on /survey-test/. For a
        // v2 form that includes its endpoint URLs (data-submit-url, …, and
        // data-run-url, from which the page derives form-render-page and
        // form-bot-challenge), which formV2Endpoint serves.
        $run_vars['run_content'] = self::previewNote($study) . str_replace(
            run_url(Run::TEST_RUN), $surveyTestUrl, $run_vars['run_content']
        );

        if (!empty($run_vars['form_v2'])) {
            // The v2 page (run/form_index) reads the run for its PWA head.
            $this->run = $testRun;
            $this->setView('run/form_index', $run_vars);
        } else {
            $this->setView('run/index', $run_vars);
        }
        return $this->sendResponse();
    }

    /**
     * Which engine this preview shows — v1 and v2 render the same item table
     * very differently, so the author must be able to tell at a glance.
     */
    public static function previewNote(SurveyStudy $study) {
        $v2 = $study->rendering_mode === 'v2';
        return '<div class="alert alert-info fmr-preview-note" role="note">'
            . '<strong>Test preview</strong> of <code>' . h($study->name) . '</code> on the '
            . ($v2 ? '<strong>form_v2</strong> engine (Form unit)' : '<strong>classic v1</strong> engine (Survey unit)')
            . '. Answers are stored in a test session.</div>';
    }

    /**
     * A v2 form's endpoint for this preview: the same RunController action
     * a participant's form calls (RunController::dispatchFormV2Preview), with
     * the preview's unit session as the context. Only a test that is already
     * in flight in this browser's /survey-test/ session can be written to —
     * the GET that renders the form starts it.
     */
    private function formV2Endpoint($token, $action, $studyName, $userId, $expired, $expires) {
        $json = function ($body, $status) {
            header('Content-Type: application/json');
            http_response_code($status);
            echo json_encode($body);
            return null;
        };
        $user = new User($userId);
        $study = $user->id ? SurveyStudy::loadByUserAndName($user, $studyName) : null;
        if (!$study || !$study->valid) {
            return $json(array('error' => 'This survey preview is no longer available.'), 404);
        }
        $slot = Session::get('test_study_data_' . (int) $study->id);
        if (!$this->hasLiveTestSession($study, $expires) || !is_array($slot) || empty($slot['unit_session_id'])) {
            return $json(array('error' => 'No current unit session', 'drop_entry' => true), 409);
        }
        $testRun = new Run(Run::TEST_RUN);
        $testRun->test_study_id = (int) $study->id;
        $data = $slot;
        $data['id'] = $slot['unit_id'];
        $runUnit = $testRun->testStudyUnit($data);
        $runSession = new RunSession(null, $testRun);
        $unitSession = new UnitSession($runSession, $runUnit, array('id' => (int) $slot['unit_session_id'], 'load' => true));
        $runSession->currentUnitSession = $unitSession;

        return (new RunController($this->site))->dispatchFormV2Preview($action, array(
            'run' => $testRun,
            'unitSession' => $unitSession,
            'study' => $study,
            'runUrl' => site_url('survey-test/' . $token),
        ));
    }

    /**
     * Is a test of $study already in flight in THIS request's session?
     * Run::testStudy() keeps the live test (incl. its unit_session_id)
     * under the per-study 'test_study_data_<id>' slot and deletes it when
     * the test finishes.
     */
    private function hasLiveTestSession($study, $tokenExpires = null) {
        $existing = Session::get('test_study_data_' . (int) $study->id);
        if (!is_array($existing) || (int) array_val($existing, 'study_id') !== (int) $study->id) {
            return false;
        }
        // A token minted after the live test's own one is a new "Test Survey"
        // click, not a continuation of that test.
        $slotExpires = array_val($existing, 'token_expires');
        if ($tokenExpires !== null && $slotExpires === null) {
            // A slot seeded before token_expires existed (pre-1.10.3) has no
            // stamp to compare: it is a test that was in flight across the
            // upgrade, and its next "Next" must continue it, not restart at
            // page 1 (or 410 on an expired-but-authentic token). Adopt this
            // token's stamp so a later click is still told apart.
            $existing['token_expires'] = (int) $tokenExpires;
            Session::set('test_study_data_' . (int) $study->id, $existing);
            return true;
        }
        if ($tokenExpires !== null && (int) $slotExpires < (int) $tokenExpires) {
            return false;
        }
        return true;
    }

    /**
     * Decrypt + validate the preview token. Emits a 403 error page and
     * returns null on a forged or malformed token; otherwise
     * array($name, $userId, $expired, $expires). Expiry is deliberately NOT
     * fatal here — indexAction still honours an expired-but-authentic token
     * when it merely continues a test session that is already in flight.
     * $expires (unix time) also identifies the "Test Survey" click that
     * minted the token: a later one means a new click, see indexAction.
     */
    private function verifyToken($token) {
        $plain = $token ? Crypto::decrypt($token) : null;
        if ($plain === null) {
            formr_error(403, 'Invalid link', 'This survey preview link is invalid.');
            return null;
        }
        // No explode() limit: exactly three fields, each of the shape it was
        // minted with. A limit of 3 would fold any extra glue-separated
        // fields into $expires, where (int) silently discards them.
        $parts = explode(self::TOKEN_GLUE, $plain);
        if (count($parts) !== 3
                || !SurveyStudy::isValidName($parts[0])
                || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            formr_error(403, 'Invalid link', 'This survey preview link is malformed.');
            return null;
        }
        list($studyName, $userId, $expires) = $parts;
        return array($studyName, (int) $userId, (int) $expires < time(), (int) $expires);
    }
}
