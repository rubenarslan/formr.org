<?php

/**
 * RunUnit Form (form_v2)
 *
 * Unlike Survey, which shares its survey_units row with a SurveyStudy (via a
 * shared primary key and a re-point of survey_run_units.unit_id at save time),
 * a Form keeps its own survey_units row of type='Form' and references its
 * SurveyStudy through the form_study_id column (patch 101). This is what lets
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

        // Same ownership gate as Survey::create — the study must belong to
        // the run's owner, not merely exist. Without it a structure import
        // (Run::importUnits / the API's PUT structure) could point a Form
        // at another user's study and read it through this run.
        $study_id = (!empty($options['study_id']) && $this->run && $this->isOwnedBy(
                'survey_studies',
                (int) $options['study_id'],
                (int) $this->run->user_id
            ))
            ? (int) $options['study_id']
            : null;

        // Linking switches the study to form_v2 (below), so a study the v2
        // engine cannot run (Item::formV2Unsupported — calculate items and
        // other server-computed values) is refused here, like a failed
        // import: alerted, and the Form is created unlinked.
        // Linking also switches the study for every run that uses it as a
        // classic Survey: refused while any does (SurveyStudy::runsUsingAsSurvey).
        // A re-save of a Form already linked to this study is not a new
        // link: the study keeps the engine it is on. An author who switched
        // it back to v1 (AdminSurveyController::switchRenderingModeAction)
        // must not have it flipped to v2 again — past that action's gates —
        // by editing the unit.
        $alreadyLinked = $study_id && $this->id
            && (int) $this->db->findValue('survey_units', ['id' => (int) $this->id], 'form_study_id') === $study_id;
        $linked = ($study_id && !$alreadyLinked) ? new SurveyStudy($study_id) : null;
        if ($linked && $linked->rendering_mode !== 'v2' && ($usedBy = $linked->runsUsingAsSurvey())) {
            alert('<strong>Study not linked to the Form.</strong> It runs as a classic Survey in: <strong>' . implode(', ', array_map('h', $usedBy))
                . '</strong>; putting it on form_v2 would change the engine for those runs\' participants. Copy the survey (download it, upload it as a new survey) and link the copy.', 'alert-danger');
            $study_id = null;
        }
        if ($study_id && $linked && ($unsupported = $linked->formV2UnsupportedItems())) {
            alert('<strong>Study not linked to the Form.</strong> It has items the form_v2 engine cannot run:<ul><li>'
                . implode('</li><li>', array_map('h', $unsupported)) . '</li></ul>', 'alert-danger');
            $study_id = null;
        }

        // Strip study_id: Survey::create's study_id branch would set
        // $this->unit_id to the study (the v1 shared-PK convention), which a
        // Form must not do — its link is form_study_id, written below. The
        // survey_run_units.unit_id re-point itself is handled by the
        // persistStudyLink() override, which Survey::create reaches whenever
        // $this->surveyStudy is set — i.e. on every re-save of an existing
        // Form, since find() has already loaded the study.
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
            if (!$alreadyLinked && $this->surveyStudy->rendering_mode !== 'v2') {
                $this->surveyStudy->adoptFormV2();
            }

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
     * Survey::persistStudyLink re-points survey_run_units.unit_id at the
     * study id, because a v1 Survey unit IS its study (shared primary key).
     * A Form has its own survey_units row and links the study through
     * form_study_id (written in create()), so the run unit must keep
     * pointing at this row: re-pointing it would turn the Form back into a
     * v1 Survey unit on the next request (the factory instantiates by
     * survey_units.type of whatever unit_id references). Only the
     * description is written here.
     */
    protected function persistStudyLink($description) {
        if ($this->run_unit_id) {
            $this->db->update(
                'survey_run_units',
                ['description' => $description],
                // Same run_id predicate as Survey::persistStudyLink().
                ['id' => $this->run_unit_id, 'run_id' => $this->run->id]
            );
        }
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
                'use_form_v2' => true,
                // Participant-page language (patch 114): threads up the same
                // fixed-shape-dict chain as use_form_v2 (RunSession::
                // executeUnitSession → Run::exec → RunController) so
                // form_index.php can set <html lang/dir> + window.fmrStrings.
                'form_language' => FormStrings::studyLanguage($study),
                // …and the study's own navigation wording on top (#322).
                'form_strings' => FormStrings::forStudy($study),
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
