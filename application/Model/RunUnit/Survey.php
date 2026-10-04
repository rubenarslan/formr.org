<?php

/**
 * RunUnit Survey
 *
 * @author ctata
 */
class Survey extends RunUnit {

    /**
     * Unit types that run through the survey pipeline. Form is the v2 Survey:
     * it extends this class, so PHP code holding a RunUnit object should test
     * `instanceof Survey`; this list exists for SQL and for type strings read
     * back from the database (e.g. survey_units.type joins).
     */
    const TYPES = ['Survey', 'Form'];

    /** TYPES as the list of an SQL `IN (…)`: constants, safe to interpolate. */
    public static function typesSql() {
        return "'" . implode("', '", self::TYPES) . "'";
    }

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

        if (!empty($options['study_id'])) {
            // The linked study must belong to the run owner. Without
            // this check, a structure-import body (or the admin
            // ajax_save_run_unit POST) can re-point this run-unit at any
            // other user's survey_studies row by id, and a participant
            // traversing the attacker's run would write into the victim's
            // results_table via UnitSession::updateSurveyStudyRecord.
            //
            // Use the run owner (not Site::getCurrentUser) so the rule
            // is uniform across entry points: the API path already
            // requires user==owner via getRunByName, but a future
            // non-API caller (cron, console import) would otherwise be
            // checked against whoever happens to be in the session.
            // Email::create takes the same approach.
            if ($this->run && $this->isOwnedBy(
                    'survey_studies',
                    (int) $options['study_id'],
                    (int) $this->run->user_id
            )) {
                // Any engine: the study's own unit row says whether the
                // placement is a Survey or a Form (SurveyStudy::syncUnitType).
                $this->unit_id = (int) $options['study_id'];
                $this->surveyStudy = $this->getStudy(true);
            }
        }

        // Only a study that exists: find() gives an unlinked unit a
        // placeholder SurveyStudy (its own id, no row), and persisting that
        // would point the placement at nothing.
        if ($this->surveyStudy && $this->surveyStudy->valid) {
            if (empty($options['description'])) {
                $options['description'] = $this->surveyStudy->name ?? 'Survey Name';
            }
            $this->persistStudyLink($options['description']);
        }

        $this->valid = true;

        return $this;
    }

    /** Survey and Form units share one dialog: the engine is the survey's. */
    public function getTemplatePath($tpl = null) {
        return parent::getTemplatePath($tpl ?? 'survey');
    }

    /**
     * Write the run unit's link to its study. A Survey (or Form) unit shares
     * the study's primary key, so linking means re-pointing
     * survey_run_units.unit_id at the study id; the study's survey_units row
     * carries the unit type (SurveyStudy::save keeps it in step with
     * rendering_mode).
     *
     * @param string $description
     */
    protected function persistStudyLink($description) {
        // run_unit_id can arrive in the save/import payload; the run_id
        // predicate stops a placement in another run from being
        // re-pointed at this (legitimately owned) study.
        $this->db->update(
                'survey_run_units',
                ['description' => $description, 'unit_id' => $this->surveyStudy->id],
                ['id' => $this->run_unit_id, 'run_id' => $this->run->id]
        );
        $this->description = $description;
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
                    'studies' => Site::getCurrentUser()->getStudies('id DESC', null, 'id, name, rendering_mode'),
                    'prepend' => $prepend,
                    'resultCount' => $this->id ? $this->getUnitSessionsCount() : null,
                    'surveyResultCount' => $this->surveyStudy ? $this->surveyStudy->getResultCount() : null,
                    'geomMeanMinutes' => $this->surveyStudy ? $this->surveyStudy->getGeometricMeanDurationMinutes() : null,
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
        $found = parent::find($id, $special, $props);
        if ($found === false) {
            // Not in this run: don't load a study for a caller-supplied
            // unit_id (the constructor is about to forget the identity).
            $this->surveyStudy = null;
            return false;
        }
        $this->getStudy();
        return $found;
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
        if (!$this->surveyStudy) {
            // Identity forgotten (unit no longer placed in the run): there
            // is no study to take a window from. "No expiry" — the callers
            // end such a session as unit_removed_from_run before routing.
            return [];
        }
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
        // Scope by session_id ALONE — a unit session's display rows are all
        // for its one survey, so the old join to `survey_items` (filtering
        // `study_id`) added nothing but a failure mode: because it was a
        // LEFT JOIN with a WHERE on the right table, a display row whose
        // item had since been DELETED or renamed (a mid-run item-table
        // re-upload — routine in diary/ESM studies) no longer matched and
        // was dropped. When the participant's most recent activity — or all
        // of it — sat on such rows, this returned NULL, the Z (inactivity)
        // deadline in getUnitSessionExpirationData() lost its anchor and
        // collapsed to "no expiry", and the queued-to-end session was
        // dequeued into limbo instead of expiring (the "expires in Xh ago"
        // orphan; other participants, whose answered items still existed,
        // expired normally). session_id is the PK of survey_unit_sessions,
        // so it pins the study without the join. See v1.10.3 changelog.
        $query = $this->db->select(array('COALESCE(`survey_items_display`.saved,`survey_items_display`.created)' => 'last_viewed'))
                ->from('survey_items_display')
                ->where('survey_items_display.session_id = :session_id')
                ->where($where ? $where : '1=1')
                // Sort by the SAME expression that is projected (v1.7.1).
                // Ordering by the raw, nullable `saved` and only then by
                // `created` does not rank rows by "last activity": in DESC
                // a NULL `saved` sorts LAST, so an item rendered after the
                // participant's most recent save (created later, saved
                // still NULL — i.e. the page they are looking at right
                // now) can never win, and the function returns the older
                // save instead. That value is the anchor for the
                // inactivity-grace branch of per-unit expiry above, so the
                // deadline was computed from a stale timestamp and the
                // session could expire while the participant was still
                // working — by up to one page's think time. `id` breaks
                // ties, which are real: createSurveyStudyRecord() stamps
                // `created` identically for every item of a page.
                // DB::raw() is required because DB_Select::order()'s
                // strict-identifier validation (v0.26.x) rejects
                // expressions.
                ->order(DB::raw('COALESCE(`survey_items_display`.`saved`,`survey_items_display`.`created`)'), $order)
                ->order('survey_items_display.id', $order)
                ->limit(1)
                ->bindParams(array('session_id' => $unitSession->id));

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
            // What this unit session's page posted, or null: a page load, a
            // stale tab's form, or a request that is not this page's (a
            // cascade, the admin, the API, a v2 endpoint): PageSubmission.
            $submission = PageSubmission::forUnitSession($unitSession);
            $request = new Request($submission ? array_merge($submission['fields'], $submission['files']) : array());
            $run = $unitSession->runSession->getRun();
            $study = $this->surveyStudy;

            if (PageSubmission::oversized()) {
                alert("The uploaded file exceeds the server's maximum file size limit.", "alert-danger");
                return ['redirect' => run_url($run->name)];
            }

            $unitSession->createSurveyStudyRecord();

            if ($study->use_paging) {
                return $this->processPagedStudy($request, $study, $unitSession, $submission);
            }
            return $this->processStudy($request, $study, $unitSession, $submission);
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

    protected function processStudy($request, $study, $unitSession, array $submission = null) {
        if ($submission !== null) {
            if ($unitSession->updateSurveyStudyRecord(array_merge($request->getParams(), $submission['files']))) {
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

    protected function processPagedStudy($request, $study, $unitSession, array $submission = null) {
        $renderer = new PagedSpreadsheetRenderer($study, $unitSession);
        $renderer->setRequest($request);

        if ($submission !== null) {
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
