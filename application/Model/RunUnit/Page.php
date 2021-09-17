<?php

class Page extends RunUnit {

    protected $body = '';
    
    protected $body_parsed = '';

    public $title;
    public $type = 'Endpage';
    public $icon = "fa-stop";

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'body');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $vars = $this->db->findRow('survey_pages', array('id' => $this->id), 'title, body, body_parsed');
            if ($vars) {
                $vars['valid'] = true;
                $this->assignProperties($vars);
            }
        }
    }

    public function create($options = []) {
        parent::create($options);

        $parsedown = new ParsedownExtra();
        $this->body_parsed = $parsedown
                ->setBreaksEnabled(true)
                ->text($this->body); // transform upon insertion into db instead of at runtime

        $this->db->insert_update('survey_pages', array(
            'id' => $this->id,
            'body' => $this->body,
            'body_parsed' => $this->body_parsed,
            'title' => $this->title,
            'end' => 0,
        ));
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'body' => $this->body,
        ));

        return $this->runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    public function test() {
        // @TODO
        // - Create a random session to get body
        // - Move session to this unit
        // - Execute the session
        //return $this->getParsedBodyAdmin($this->body);
    }
    
    public function find($id, $special = false, $props = []) {
        parent::find($id, $special, $props);
        $this->type = 'Endpage';
        
        return $this;
    }
    
    public function getUnitSessionOutput(UnitSession $unitSession) {
        $output = [];
        if ($unitSession->isExecutedByCron()) {
            $this->getParsedBody($this->body, $unitSession);
            $output['log'] = array_val($this->errors, 'log', []);
            $output['wait_user'] = true;
            return $output;
        }

        $this->body_parsed = $this->getParsedBody($this->body, $unitSession);
        if ($this->body_parsed === false) {
            $output['wait_opencpu'] = true; // wait for openCPU to be fixed!
            $output['log'] = array_val($this->errors, 'log', []);
            return $output;
        }
        
        $output['content'] = do_run_shortcodes($this->body_parsed, $unitSession->runSession->getRun()->name, $unitSession->runSession->session);
        $output['end_session'] = true;
        $output['end_run_session'] = true;
        $output['log'] = $this->getLogMessage('ended');
        
        return $output;
    }

}
