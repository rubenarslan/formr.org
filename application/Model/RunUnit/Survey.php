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
        $dialog = Template::get($this->getTemplatePath(), array(
                    'survey' => $this->surveyStudy,
                    'studies' => Site::getCurrentUser()->getStudies('id DESC', null, 'id, name'),
                    'prepend' => $prepend,
                    'resultCount' => $this->id ? $this->getUnitSessionsCount() : null,
                    'time' => $this->surveyStudy ? $this->surveyStudy->getAverageTimeItTakes() : null,
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
        $data = [];

        $expire_invitation = (int) $this->surveyStudy->expire_invitation_after;
        $grace_period = (int) $this->surveyStudy->expire_invitation_grace;
        $expire_inactivity = (int) $this->surveyStudy->expire_after;

        if ($expire_inactivity === 0 && $expire_invitation === 0) {
            return $data;
        } else {
            $now = time();

            $last_active = $this->getUnitSessionLastVisit($unitSession); // when was the user last active on the study
            $expire_invitation_time = $expire_inactivity_time = 0; // default to 0 (means: other values supervene. users only get here if at least one value is nonzero)
            if ($expire_inactivity !== 0 && $last_active != null && strtotime($last_active)) {
                $expire_inactivity_time = strtotime($last_active) + ($expire_inactivity * 60);
            }
            $invitation_sent = $unitSession->created;
            if ($expire_invitation !== 0 && $invitation_sent && strtotime($invitation_sent)) {
                $expire_invitation_time = strtotime($invitation_sent) + ($expire_invitation * 60);
                if ($grace_period !== 0 && $last_active) {
                    $expire_invitation_time = $expire_invitation_time + ($grace_period * 60);
                }
            }

            $expire = max($expire_inactivity_time, $expire_invitation_time);

            $data['expires'] = max(0, $expire_invitation_time);
            $data['expired'] = ($data['expires'] > 0) && ($now > $data['expires']);
            $data['queued'] = UnitSessionQueue::QUEUED_TO_EXECUTE;

            return $data;
        }
    }

    public function getUnitSessionLastVisit(UnitSession $unitSession) {
        // use created (item render time) if viewed time is lacking
        $arr = $this->db->select(array('COALESCE(`survey_items_display`.shown,`survey_items_display`.created)' => 'last_viewed'))
                ->from('survey_items_display')
                ->leftJoin('survey_items', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
                ->where('survey_items_display.session_id IS NOT NULL')
                ->where('survey_items.study_id = :study_id')
                ->order('survey_items_display.shown', 'desc')
                ->order('survey_items_display.created', 'desc')
                ->limit(1)
                ->bindParams(array('session_id' => $unitSession->id, 'study_id' => $this->id))
                ->fetch();

        return isset($arr['last_viewed']) ? $arr['last_viewed'] : null;
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        try {
            $request = new Request(array_merge($_POST, $_FILES));
            $run = $unitSession->runSession->getRun();
            $study = $this->surveyStudy;

            if (Request::isHTTPPostRequest() && !Session::canValidateRequestToken($request)) {
                return ['redirect' => run_url($run->name)];
            }

            $unitSession->createSurveyStudyRecord();

            if ($study->use_paging) {
                return $this->processPagedStudy($request, $study, $unitSession);
            } else {
                return $this->processStudy($request, $study, $unitSession);
            }
        } catch (Exception $e) {
            $data = [
                'log' => $this->getLogMessage('error_survey', $e->getMessage()),
                'content' => '',
            ];
            formr_log_exception($e, __CLASS__);
            return $data;
        }
    }

    protected function processStudy($request, $study, $unitSession) {
        if (Request::isHTTPPostRequest()) {
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

    protected function processPagedStudy($request, $study, $unitSession) {
        $renderer = new PagedSpreadsheetRenderer($study, $unitSession);
        $renderer->setRequest($request);
        
        if (Request::isHTTPPostRequest()) {
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
