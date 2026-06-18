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

    public function indexAction($token = null) {
        $parsed = $this->verifyToken($token);
        if ($parsed === null) {
            return; // verifyToken already emitted the error page
        }
        list($studyName, $userId) = $parsed;

        $user = new User($userId);
        if (!$user->id) {
            return formr_error(403, 'Forbidden', 'Unknown user for this preview link.');
        }
        $study = SurveyStudy::loadByUserAndName($user, $studyName);
        if (empty($study) || !$study->valid) {
            return formr_error(404, 'Not found', 'This survey preview is no longer available.');
        }

        // Seed the test data the TEST_RUN exec reads (mirror of
        // AdminSurveyController::accessAction), in THIS request's own session
        // (cookie path /survey-test/, see determine_session_context()).
        Session::set('test_study_data', array(
            'study_id' => $study->id,
            'study_name' => $study->name,
            'unit_id' => $study->id,
            'data' => $study->getItems('id, name, type'),
        ));

        $testRun = new Run(Run::TEST_RUN);
        $run_vars = $testRun->exec($user);
        if (!$run_vars) {
            return formr_error(500, 'Invalid Execution', 'The execution generated no output');
        }
        if (!empty($run_vars['redirect'])) {
            return $this->request->redirect(site_url('survey-test/' . $token));
        }

        $run_vars['bodyClass'] = 'fmr-run';
        $assets = Config::get('assets.frontend');
        $run_vars['js'] = array(array_val($assets, 'js', array()));
        // Point the form / continue links at this preview URL, not the raw
        // TEST_RUN run URL, so multi-page tests stay on /survey-test/.
        $run_vars['run_content'] = str_replace(
            run_url(Run::TEST_RUN), site_url('survey-test/' . $token), $run_vars['run_content']
        );

        $this->setView('run/index', $run_vars);
        return $this->sendResponse();
    }

    /**
     * Decrypt + validate the preview token. Emits a 403/410 error page and
     * returns null on a bad or expired token; otherwise array($name, $userId).
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
        if ((int) $expires < time()) {
            formr_error(410, 'Link expired', 'This survey preview link has expired. Open the survey again and click "Test Survey".');
            return null;
        }
        return array($studyName, (int) $userId);
    }
}
