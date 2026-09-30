<?php

/**
 * RunUnit Form (form_v2)
 *
 * A Form is a Survey unit whose study is on the form_v2 engine. Like a
 * Survey it shares the study's survey_units row (survey_run_units.unit_id is
 * the study id); that row's type is 'Form' while the study's rendering_mode
 * is 'v2' and 'Survey' otherwise (SurveyStudy::save), which is how the
 * RunUnit factory instantiates a Form at request time. Linking a Form puts
 * its study on v2; getUnitSessionOutput renders v2 through FormRenderer and
 * falls back to the Survey pipeline for a study that is not (or no longer)
 * on v2.
 */
class Form extends Survey {

    public $icon = "fa-wpforms";
    public $type = "Form";

    public function __construct(Run $run = null, array $props = []) {
        parent::__construct($run, $props);
    }

    public function create($options = []) {
        // A run bundle exported with survey details carries the study as
        // `survey_data` (Run::export). Materialise it first — study only,
        // no unit: Survey::create's survey_data path lets createFromData
        // place a *Survey* unit as a side effect, which is exactly what a
        // Form must not do — then link it like a dropdown-chosen study.
        // createFromData reuses a same-named study of the current user, so
        // re-importing a bundle updates that study rather than duplicating
        // it. On failure the errors are already alerted; the Form is still
        // created, unlinked, so the run's structure survives the import.
        if (isset($options['survey_data'])) {
            $opts = $options;
            $opts['importing'] = true;
            $opts['place_unit'] = false;
            $study = SurveyStudy::createFromData($options['survey_data'], $opts);
            unset($options['survey_data']);
            if ($study && $study->valid) {
                $options['study_id'] = $study->id;
            }
        }

        // Survey::create links the study (ownership, linkRefusal, the
        // re-point); linking a new study puts it on v2. A re-save of a Form
        // already linked to this study is not a new link: the study keeps
        // the engine it is on. An author who switched it back to v1
        // (AdminSurveyController::switchRenderingModeAction) must not have it
        // flipped to v2 again — past that action's gates — by editing the
        // unit.
        $linkedBefore = (int) $this->unit_id;
        parent::create($options);

        $study = $this->surveyStudy;
        if ($study && $study->valid && (int) $study->id !== $linkedBefore && $study->rendering_mode !== 'v2') {
            $study->adoptFormV2();
        }
        return $this;
    }

    /**
     * Linking switches the study to form_v2 (Form::create), so a study the
     * v2 engine cannot run (Item::formV2Unsupported — calculate items and
     * other server-computed values) is refused, and so is one that runs as a
     * classic Survey anywhere (SurveyStudy::runsUsingAsSurvey): switching it
     * would change the engine under those runs' participants. A study
     * already linked here is a re-save, not a new link.
     */
    protected function linkRefusal(SurveyStudy $study) {
        if ((int) $study->id === (int) $this->unit_id || $study->rendering_mode === 'v2') {
            return null;
        }
        if ($usedBy = $study->runsUsingAsSurvey()) {
            return '<strong>Study not linked to the Form.</strong> It runs as a classic Survey in: <strong>' . implode(', ', array_map('h', $usedBy))
                . '</strong>; putting it on form_v2 would change the engine for those runs\' participants. Copy the survey (download it, upload it as a new survey) and link the copy.';
        }
        if ($unsupported = $study->formV2UnsupportedItems()) {
            return '<strong>Study not linked to the Form.</strong> It has items the form_v2 engine cannot run:<ul><li>'
                . implode('</li><li>', array_map('h', $unsupported)) . '</li></ul>';
        }
        return null;
    }

    /**
     * The linked study if it renders in v2, else null. The one rule for "is
     * this a v2 form": it picks the renderer here and gates the form_v2
     * endpoints (RunController::formV2Study), so the two cannot disagree.
     *
     * @return SurveyStudy|null
     */
    public function v2Study() {
        $study = $this->surveyStudy;
        if (!$study || empty($study->id)) {
            $study = $this->getStudy(true);
        }
        return ($study && !empty($study->id) && $study->rendering_mode === 'v2') ? $study : null;
    }

    /**
     * Override Survey's rendering to branch on rendering_mode. When the linked
     * SurveyStudy is v2-rendered, the FormRenderer emits a single-document HTML
     * layout and GET-only output (POSTs for v2 forms go through the
     * form-page-submit AJAX endpoint, not here). Any non-v2 study falls through
     * to the legacy Survey pipeline unchanged.
     */
    public function getUnitSessionOutput(UnitSession $unitSession) {
        $study = $this->v2Study();
        if (!$study) {
            return parent::getUnitSessionOutput($unitSession);
        }

        try {
            $unitSession->createSurveyStudyRecord();

            // A v2 form is completed by its final page submit
            // (FormSubmissionService::apply ends the unit session), never by
            // a GET: the document is the same whatever has been answered.
            $renderer = new FormRenderer($study, $unitSession);
            $renderer->processItems();

            return [
                'content' => $renderer->render(),
                // The v2 page's own state, passed whole through
                // RunSession::executeUnitSession and Run::exec; its presence
                // makes the controller render run/form_index, which reads it
                // for <html lang/dir> and window.fmrStrings (the study's
                // language, patch 114, and its own navigation wording, #322).
                'form_v2' => [
                    'language' => FormStrings::studyLanguage($study),
                    'strings' => FormStrings::forStudy($study),
                ],
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
