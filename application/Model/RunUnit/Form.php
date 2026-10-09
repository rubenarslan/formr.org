<?php

/**
 * RunUnit Form: a Survey unit whose study runs on v2.
 *
 * Like a Survey unit it shares the study's survey_units row
 * (survey_run_units.unit_id is the study id); that row's type is 'Form'
 * while the study's rendering_mode is 'v2' and 'Survey' otherwise
 * (SurveyStudy::syncUnitType), which is how the RunUnit factory picks this
 * class. There is no separate way to create one: a Survey unit linked to a
 * v2 study is a Form. getUnitSessionOutput renders v2 through FormRenderer
 * and falls back to the Survey pipeline for a study no longer on v2.
 */
class Form extends Survey {

    public $icon = "fa-wpforms";
    public $type = "Form";

    public function __construct(Run $run = null, array $props = []) {
        parent::__construct($run, $props);
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
        // A Form that has ended (or expired) takes no answers. It is shown
        // again only where a run has no Stop after it (RunSession::execute's
        // ended branch re-runs the last unit): render nothing rather than a
        // form whose every write is refused.
        if ($unitSession->ended !== null || $unitSession->expired !== null) {
            return ['content' => ''];
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
            // A deadlock or lock wait among concurrent first renders (the
            // iteration, the display rows, the R-call allowlist): render once
            // more, as Survey does, instead of a blank page.
            if ($this->db->retryTransaction($e) && $this->retryOutput) {
                $this->retryOutput = false;
                sleep(rand(1, 4));
                return $this->getUnitSessionOutput($unitSession);
            }
            formr_log_exception($e, __CLASS__ . '-' . $e->getCode());
            $this->db->logLastStatement($e);
            return [
                'log' => $this->getLogMessage('error_survey', $e->getMessage()),
                'content' => '',
            ];
        }
    }
}
