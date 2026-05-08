<?php

/**
 * RunUnit Survey
 *
 * @author ctata
 */
class Survey extends RunUnit {

    /**
     * Survey study assigned to the Survey unit
     *
     * @var SurveyStudy
     */
    public $surveyStudy;
    public $icon = "fa-pencil-square-o";
    public $type = "Survey";
    public $export_attribs = array('type', 'description', 'position', 'special');

	/**
	 * 
	 * @param Run $run
	 * @param array $props
	 */
    public function __construct(Run $run = null, array $props = []) {
        parent::__construct($run, $props);
    }

    public function create($options = []) {
        if (isset($options['survey_data'])) {
            $options['importing'] = true;
            SurveyStudy::createFromData($options['survey_data'], $options);
            return $this;
        }

        parent::create($options);

        if (!empty($options['study_id']) && $this->db->entry_exists('survey_studies', ['id' => (int)$options['study_id']])) {
            $this->unit_id = (int) $options['study_id'];
            $this->surveyStudy = $this->getStudy(true);
        }

        if (empty($options['description']) && $this->surveyStudy) {
            $options['description'] = $this->surveyStudy->name ?? 'Survey Name';
        }

        if ($this->surveyStudy) {
            $this->db->update(
                    'survey_run_units',
                    ['description' => $options['description'], 'unit_id' => $this->surveyStudy->id],
                    ['id' => $this->run_unit_id]
            );
        }

        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $expiration_settings = [];
        if ($this->surveyStudy) {
            $expire_invitation_after = (int) $this->surveyStudy->expire_invitation_after;
            $expire_invitation_grace = (int) $this->surveyStudy->expire_invitation_grace;
            $expire_after = (int) $this->surveyStudy->expire_after;

            if ($expire_invitation_after > 0) {
                $expiration_settings[] = "Start editing within {$expire_invitation_after} minute(s)";
            }
            if ($expire_invitation_grace > 0) {
                $expiration_settings[] = "Finish editing within {$expire_invitation_grace} minute(s) after the access window closed";
            }
            if ($expire_after > 0) {
                $expiration_settings[] = "Inactivity expiration after {$expire_after} minute(s)";
            }
        }

        $dialog = Template::get($this->getTemplatePath(), array(
                    'survey' => $this->surveyStudy,
                    'studies' => Site::getCurrentUser()->getStudies('id DESC', null, 'id, name'),
                    'prepend' => $prepend,
                    'resultCount' => $this->id ? $this->getUnitSessionsCount() : null,
                    'time' => $this->surveyStudy ? $this->surveyStudy->getAverageTimeItTakes() : null,
                    'surveyResultCount' => $this->surveyStudy ? $this->surveyStudy->getResultCount() : null,
                    'expirationSettings' => $expiration_settings,
        ));

        return parent::runDialog($dialog);
    }

    public function getStudy($force = true) {
        if ($force || ($this->surveyStudy == null && $this->unit_id)) {
            $this->surveyStudy = new SurveyStudy($this->unit_id);
        }

        return $this->surveyStudy;
    }

    public function load() {
        parent::load();
        if ($this->unit_id && !$this->surveyStudy) {
            $this->surveyStudy = new SurveyStudy();
        }

        return $this;
    }

    /**
     * @doc {inherit}
     */
    public function find($id, $special = false, $props = []) {
        parent::find($id, $special, $props);
        $this->getStudy();
    }

    public function getUnitSessionExpirationData(UnitSession $unitSession) {
        // Combines the three Access Window settings per the wiki spec
        // (https://github.com/rubenarslan/formr.org/wiki/Expiry):
        //
        //   pre-access (no first_submit yet):
        //     X > 0        ⇒ deadline = invitation + X
        //     X = 0        ⇒ never expires (user "kept here forever")
        //
        //   post-access (user has submitted at least one real item):
        //     Y = 0, Z = 0 ⇒ never expires
        //     Y > 0, Z = 0 ⇒ deadline = invitation + X + Y    (hard cap)
        //     Y = 0, Z > 0 ⇒ deadline = last_active + Z       (sliding)
        //     Y > 0, Z > 0 ⇒ deadline = MIN(invite+X+Y, last_active+Z)
        //
        // Pre-fix the rules ran in fixed order with each one *overwriting*
        // the previous, which (a) made X-rule fire unconditionally even
        // for users in the middle of editing (W4.a — the prod-reported
        // bug) and (b) anchored the grace block on first_submit instead
        // of invitation+X, so early starters got less total time than
        // late starters. See tests/e2e/EXPIRY_PLAN.md "Fix 3" and the
        // wiki-cell matrix in tests/e2e/survey-expiry-matrix.spec.js.
        $X = (int) $this->surveyStudy->expire_invitation_after;
        $Y = (int) $this->surveyStudy->expire_invitation_grace;
        $Z = (int) $this->surveyStudy->expire_after;

        if ($X === 0 && $Z === 0) {
            // No deadline either pre- or post-access. Y alone is degenerate
            // (the wiki doesn't define behaviour for it — Y is always
            // measured relative to X-anchored windows). Returning empty
            // signals "no expiry" to UnitSession::isExpired so the queue
            // doesn't get a stored expires for this unit.
            return [];
        }

        $now = time();
        $invitation_sent_str = $unitSession->created;
        $invitation_sent = $invitation_sent_str ? strtotime($invitation_sent_str) : 0;

        // first_submit = the earliest items_display.saved that is NOT the
        // bulk auto-save at invitation_sent and is more than 2 s after
        // invitation_sent (so auto-fill items like browser/IP saved
        // synchronously with createSurveyStudyRecord don't count as
        // "started editing").
        $first_submit_str = $invitation_sent_str
            ? $this->getUnitSessionFirstVisit(
                $unitSession,
                'survey_items_display.saved != :invitation_sent',
                ['invitation_sent' => $invitation_sent_str]
            )
            : null;
        $started = false;
        if ($first_submit_str !== null && strtotime($first_submit_str)) {
            $started = (strtotime($first_submit_str) - $invitation_sent) > 2;
        }

        $expires = 0;
        if (!$started) {
            // Pre-access: only X applies. Z would only apply post-access
            // per the wiki — applying Z pre-access via the items_display
            // .created fallback (which approximately equals invitation_sent)
            // would just clip to invitation+Z, which the wiki does NOT
            // specify and which broke W5.a.
            if ($X > 0 && $invitation_sent) {
                $expires = $invitation_sent + $X * 60;
            }
        } else {
            // Post-access: combine Y and Z with MIN, fall back to "never"
            // if both are zero.
            $candidates = [];
            if ($Y > 0 && $invitation_sent) {
                // Wiki: "users that accessed your survey have at most
                // X+Y minutes to fill out the survey". Anchored on
                // invitation, NOT on first_submit (which would punish
                // early starters).
                $candidates[] = $invitation_sent + ($X + $Y) * 60;
            }
            if ($Z > 0) {
                $last_active_str = $this->getUnitSessionLastVisit($unitSession);
                if ($last_active_str !== null && strtotime($last_active_str)) {
                    $candidates[] = strtotime($last_active_str) + $Z * 60;
                }
            }
            if ($candidates) {
                $expires = min($candidates);
            }
        }

        return [
            'expires' => max(0, $expires),
            'expired' => ($expires > 0) && ($now > $expires),
            'queued'  => UnitSessionQueue::QUEUED_TO_END,
        ];
    }

    public function getUnitSessionLastVisit(UnitSession $unitSession, $order = 'desc', $where = null, array $whereBinds = []) {
        // use created (item render time) if viewed time is lacking.
        // $where: optional extra WHERE fragment with `:placeholder`s.
        // $whereBinds: associative array of bind values for those
        // placeholders. Pre-fix the caller interpolated values into
        // $where directly — fine for current callers (DB-sourced
        // values only) but a bad pattern. See EXPIRY_AUDIT.md §1's
        // "pre-existing pattern propagated" note.
        $query = $this->db->select(array('COALESCE(`survey_items_display`.saved,`survey_items_display`.created)' => 'last_viewed'))
                ->from('survey_items_display')
                ->leftJoin('survey_items', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
                ->where('survey_items_display.session_id IS NOT NULL')
                ->where('survey_items.study_id = :study_id')
                ->where($where ? $where : '1=1')
                ->order('survey_items_display.saved', $order)
                ->order('survey_items_display.created', $order)
                ->limit(1)
                ->bindParams(array('session_id' => $unitSession->id, 'study_id' => $this->surveyStudy->id));

        if ($whereBinds) {
            $query->bindParams($whereBinds);
        }

        $arr = $query->fetch();
        return isset($arr['last_viewed']) ? $arr['last_viewed'] : null;
    }

    public function getUnitSessionFirstVisit(UnitSession $unitSession, $where = null, array $whereBinds = []) {
        return $this->getUnitSessionLastVisit($unitSession, 'asc', $where, $whereBinds);
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        try {
            $request = new Request(array_merge($_POST, $_FILES));
            $run = $unitSession->runSession->getRun();
            $study = $this->surveyStudy;
            $ignore_post = false;

            // Check for exceeded limits
            $postMaxSize = convertToBytes(ini_get('post_max_size'));
            if(isset($_SERVER['CONTENT_LENGTH'])) {
                $contentLength = (int) $_SERVER['CONTENT_LENGTH'];
            } else {
                $contentLength = 0;
            }

            if (Request::isHTTPPostRequest() && $contentLength > $postMaxSize) {
                alert("The uploaded file exceeds the server's maximum file size limit.", "alert-danger");
                return ['redirect' => run_url($run->name)];
            }

            $unitSession->createSurveyStudyRecord();

            if ($study->use_paging) {
                $result = $this->processPagedStudy($request, $study, $unitSession, $ignore_post);
            } else {
                $result = $this->processStudy($request, $study, $unitSession, $ignore_post);
            }
            if($ignore_post) {
                $result['log'] = $this->getLogMessage('security_token_error');
            }

            return $result;
        } catch (Exception $e) {
			if ($this->db->retryTransaction($e) && $this->retryOutput) {
				$this->retryOutput = false;
				sleep(rand(1, 4));
				return $this->getUnitSessionOutput($unitSession);
			}

            $data = [
                'log' => $this->getLogMessage('error_survey', $e->getMessage()),
                'content' => '',
            ];

            formr_log_exception($e, __CLASS__ . '-' . $e->getCode());
            $this->db->logLastStatement($e);

            return $data;
        }
    }

    protected function processStudy($request, $study, $unitSession, $ignore_post = false) {
        if (Request::isHTTPPostRequest() && !$ignore_post) {
            if ($unitSession->updateSurveyStudyRecord(array_merge($request->getParams(), $_FILES))) {
                return ['redirect' => run_url($unitSession->runSession->getRun()->name), 'log' => $this->getLogMessage('survey_filling_out')];
            }
        }

        $renderer = new SpreadsheetRenderer($study, $unitSession);
        $renderer->processItems();
        if ($renderer->studyCompleted()) {
            return ['end_session' => true, 'move_on' => true, 'log' => $this->getLogMessage('survey_completed')];
        } else {
            return ['content' => $renderer->render()];
        }
    }

    protected function processPagedStudy($request, $study, $unitSession, $ignore_post = false) {
        $renderer = new PagedSpreadsheetRenderer($study, $unitSession);
        $renderer->setRequest($request);
        
        if (Request::isHTTPPostRequest() && !$ignore_post) {
            $options = $renderer->getPostedItems();
            if ($unitSession->updateSurveyStudyRecord($options['posted'])) {
                Session::set('is-survey-post', true); // FIX ME
                return ['redirect' => $options['next_page'], 'log' => $this->getLogMessage('survey_filling_out')];
            }
        }
        
        $renderer->processItems();
        if ($renderer->redirect) {
            return ['redirect' => $renderer->redirect];
        }
        
        if ($renderer->studyCompleted()) {
            return ['end_session' => true, 'move_on' => true, 'log' => $this->getLogMessage('survey_completed')];
        } else {
            return ['content' => $renderer->render()];
        }
    }

}
