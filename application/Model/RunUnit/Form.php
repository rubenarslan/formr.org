<?php

/**
 * RunUnit Form (form_v2)
 *
 * Phase 0 of the form_v2 refactor: inherits the legacy Survey rendering
 * pipeline unchanged. The distinct type/icon and the rendering_mode='v2' stamp
 * on the linked SurveyStudy row exist so that later phases can swap in a new
 * renderer without retrofitting existing Survey units. See plan_form_v2.md.
 */
class Form extends Survey {

    public $icon = "fa-wpforms";
    public $type = "Form";

    public function __construct(Run $run = null, array $props = []) {
        parent::__construct($run, $props);
    }

    public function create($options = []) {
        parent::create($options);

        if ($this->surveyStudy && $this->surveyStudy->id) {
            $this->db->update(
                'survey_studies',
                ['rendering_mode' => 'v2'],
                ['id' => (int) $this->surveyStudy->id]
            );
        }

        return $this;
    }
}
