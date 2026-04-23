<?php

/**
 * RunUnit Form (form_v2)
 *
 * Unlike Survey, which shares its survey_units row with a SurveyStudy (via a
 * shared primary key and a re-point of survey_run_units.unit_id at save time),
 * a Form keeps its own survey_units row of type='Form' and references its
 * SurveyStudy through the form_study_id column (patch 048). This is what lets
 * the RunUnit factory instantiate a Form (not a Survey) when loading the run
 * at request time.
 *
 * Phase 0: the unit exists; no rendering behaviour change.
 * Phase 1: getUnitSessionOutput branches on SurveyStudy.rendering_mode='v2'
 * and uses FormRenderer + form_index view + form-page-submit AJAX endpoint.
 */
class Form extends Survey {

    public $icon = "fa-wpforms";
    public $type = "Form";

    public function __construct(Run $run = null, array $props = []) {
        parent::__construct($run, $props);
    }

    public function create($options = []) {
        $study_id = (!empty($options['study_id']) && $this->db->entry_exists('survey_studies', ['id' => (int) $options['study_id']]))
            ? (int) $options['study_id']
            : null;

        // Strip study_id so Survey::create skips its re-point-the-run_unit logic.
        // We want survey_run_units.unit_id to stay pointing at this Form's own row.
        $opts = $options;
        unset($opts['study_id']);

        parent::create($opts);

        if ($study_id && $this->id) {
            $this->db->update(
                'survey_units',
                ['form_study_id' => $study_id],
                ['id' => (int) $this->id]
            );
            $this->surveyStudy = new SurveyStudy($study_id);

            $this->db->update(
                'survey_studies',
                ['rendering_mode' => 'v2'],
                ['id' => $study_id]
            );

            $description = !empty($options['description'])
                ? $options['description']
                : ($this->surveyStudy->name ?? 'Form');
            if ($this->run_unit_id) {
                $this->db->update(
                    'survey_run_units',
                    ['description' => $description],
                    ['id' => $this->run_unit_id]
                );
            }
            $this->description = $description;
        }

        $this->valid = true;
        return $this;
    }

    /**
     * Override Survey::getStudy: for a Form the SurveyStudy id comes from
     * survey_units.form_study_id, not from $this->unit_id. Without this,
     * getStudy() would try to instantiate SurveyStudy(form's own id) and
     * silently return an empty object.
     */
    public function getStudy($force = true) {
        if (!$force && $this->surveyStudy !== null && $this->surveyStudy->id) {
            return $this->surveyStudy;
        }
        if ($this->id) {
            $form_study_id = $this->db->findValue('survey_units', ['id' => (int) $this->id], 'form_study_id');
            if ($form_study_id) {
                $this->surveyStudy = new SurveyStudy((int) $form_study_id);
            }
        }
        return $this->surveyStudy;
    }

    public function load() {
        parent::load();
        $this->getStudy(true);
        return $this;
    }

    /**
     * Override Survey's rendering to branch on rendering_mode. When the linked
     * SurveyStudy is v2-rendered, the FormRenderer emits a single-document HTML
     * layout and GET-only output (POSTs for v2 forms go through the
     * form-page-submit AJAX endpoint, not here). Any non-v2 study falls through
     * to the legacy Survey pipeline unchanged.
     */
    public function getUnitSessionOutput(UnitSession $unitSession) {
        $study = $this->surveyStudy ?: $this->getStudy(true);
        $v2 = $study && isset($study->rendering_mode) && $study->rendering_mode === 'v2';
        if (!$v2) {
            return parent::getUnitSessionOutput($unitSession);
        }

        try {
            $unitSession->createSurveyStudyRecord();

            $renderer = new FormRenderer($study, $unitSession);
            $renderer->processItems();
            if ($renderer->studyCompleted()) {
                return [
                    'end_session' => true,
                    'move_on' => true,
                    'log' => $this->getLogMessage('survey_completed'),
                ];
            }

            return [
                'content' => $renderer->render(),
                'use_form_v2' => true,
            ];
        } catch (Exception $e) {
            formr_log_exception($e, __CLASS__ . '-' . $e->getCode());
            $this->db->logLastStatement($e);
            return [
                'log' => $this->getLogMessage('error_survey', $e->getMessage()),
                'content' => '',
            ];
        }
    }
}
