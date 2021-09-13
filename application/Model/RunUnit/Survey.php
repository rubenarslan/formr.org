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

        parent::create($options);
        
        // If survey_data is present (an object with "name", "items", "settings" entries)
        // then create/update the survey and set $options[unit_id] as Survey's ID
        /*
        if (!empty($options['survey_data'])) {
            if ($created = $this->createFromData($options['survey_data'])) {
                $options = array_merge($options, $created);
                $this->id = $created['id'];
                $this->name = $created['name'];
                $this->results_table = $created['results_table'];
            }
        }
         * 
         */

        // this unit type is a bit special
        // all other unit types are created only within runs
        // but surveys are semi-independent of runs
        // so it is possible to add a survey, without specifying which one at first
        // and to then choose one.
        // thus, we "mock" a survey at first
        if (count($options) === 1 || isset($options['mock'])) {
            $this->valid = true;
        } else { // and link it to the run only later
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
            
        }
        
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
            $this->surveyStudy = null;
            $study = new SurveyStudy($this->unit_id);
            if (Site::getCurrentUser()->created($study)) {
                $this->surveyStudy = $study;
            }
        }
        
        return $this->surveyStudy;
    }
    
    public function load() {
        parent::load();
        if ($this->unit_id) {
            $this->surveyStudy = new SurveyStudy();
        }
    }
    
    /**
     * @doc {inherit}
     */
    public function find($id, $special = false, $props = []) {
        parent::find($id, $special, $props);
        $this->getStudy();
    }
}
