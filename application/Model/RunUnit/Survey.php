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

			// Expire if the user started filling the survey and then stopped
			// This uses "Inactivity Expiration" but can be overriden by "Start editing within"
            $last_active = $this->getUnitSessionLastVisit($unitSession);
            $expires = $expire_invitation_time = $expire_inactivity_time = 0;
            if ($expire_inactivity !== 0 && $last_active != null && strtotime($last_active)) {
                $expires = strtotime($last_active) + ($expire_inactivity * 60);
            }
			
			// Get expiration time based on when invitation was sent or user arrived in unit but not reacted
			// This uses "Start editing within"
            $invitation_sent = $unitSession->created;
            if ($expire_invitation !== 0 && $invitation_sent && strtotime($invitation_sent)) {
                $expires = strtotime($invitation_sent) + ($expire_invitation * 60);
            }

			// Get expiration time if user already started filling and there is a maximum time to spend on the survey
			// This uses "finishing editing within"
			$first_active = $this->getUnitSessionFirstVisit($unitSession);
			$first_submit = $this->getUnitSessionFirstVisit($unitSession, 'survey_items_display.saved != "' . $invitation_sent . '"');

			if ($last_active && $last_active != $invitation_sent && 
				$grace_period !== 0 && 
				$first_active != null && strtotime($first_active) &&
				$first_submit != null && strtotime($first_submit) &&
                strtotime($first_submit) - strtotime($invitation_sent) > 2
			) {
				$expires = strtotime($first_submit) + ($grace_period * 60);
            }

            $data['expires'] = max(0, $expires);
            $data['expired'] = ($data['expires'] > 0) && ($now > $data['expires']);
            $data['queued'] = UnitSessionQueue::QUEUED_TO_END;

            return $data;
        }
    }

    public function getUnitSessionLastVisit(UnitSession $unitSession, $order = 'desc', $where = null) {
        // use created (item render time) if viewed time is lacking
        $arr = $this->db->select(array('COALESCE(`survey_items_display`.saved,`survey_items_display`.created)' => 'last_viewed'))
                ->from('survey_items_display')
                ->leftJoin('survey_items', 'survey_items_display.session_id = :session_id', 'survey_items.id = survey_items_display.item_id')
                ->where('survey_items_display.session_id IS NOT NULL')
                ->where('survey_items.study_id = :study_id')
				->where($where ? $where : '1=1')
                ->order('survey_items_display.saved', $order)
                ->order('survey_items_display.created', $order)
                ->limit(1)
                ->bindParams(array('session_id' => $unitSession->id, 'study_id' => $this->surveyStudy->id))
                ->fetch();

        return isset($arr['last_viewed']) ? $arr['last_viewed'] : null;
    }
	
	function getUnitSessionFirstVisit(UnitSession $unitSession, $where = null) {
		return $this->getUnitSessionLastVisit($unitSession, 'asc', $where);
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

            // Validate request token for POST requests only
            if (Request::isHTTPPostRequest() AND !Request::isAjaxRequest()) {
                // Check if it's actually a form submission or possibly an asset request
                $isFormSubmission = false;
                
                // Determine if this is likely a form submission by checking for common form fields
                // This helps distinguish between actual form submissions and asset requests
                if (!empty($_POST)) {
                    // If POST has data, it's likely a form submission
                    $isFormSubmission = true;
                }
                
                if ($isFormSubmission) {
                    // Log details about the form submission before validation
                    if (DEBUG) {
                        error_log("Processing form submission in survey: " . $run->name);
                        error_log("Form fields: " . implode(", ", array_keys($_POST)));
                    }
                    
                    // Attempt token validation
                    $isValid = Session::canValidateRequestToken($request);
                    
                    if (!$isValid) {
                        // Log detailed information about the failed validation
                        if (DEBUG) {
                            error_log("Form submission failed token validation in survey: " . $run->name);
                            error_log("POST data keys: " . implode(", ", array_keys($_POST)));
                            error_log("Referrer: " . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'not set'));
                            error_log("User Agent: " . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'not set'));
                        }
                        
                        // Add a more descriptive error message with recovery suggestion
                        alert("Your form could not be submitted due to a security verification issue. This can happen if the page has been open for a long time or was refreshed in another tab. Please try again from this page.", "alert-warning");

                        // Re-render current page without processing POSTed data by passing a flag downstream
                        $ignore_post = true;
                    }
                }
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
