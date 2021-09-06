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
        return $this->getParsedBodyAdmin($this->body);
    }
    
    public function find($id, $special = false) {
        parent::find($id, $special);
        $this->type = 'Endpage';
        
        return $this;
    }

    public function exec() {
        if ($this->called_by_cron) {
            $this->getParsedBody($this->body); // make report before showing it to the user, so they don't have to wait
            $this->session_result = "ended_study_by_queue";
            $this->logResult();
            return true; // never show to the cronjob
        }

        $run_name = $sess_code = null;
        if ($this->run_session) {
            $run_name = $this->run_session->run_name;
            $sess_code = $this->run_session->session;
            $this->run_session->end();
        }

        $this->body_parsed = $this->getParsedBody($this->body);
        if ($this->body_parsed === false) {
            return true; // wait for openCPU to be fixed!
        }

        $body = do_run_shortcodes($this->body_parsed, $run_name, $sess_code);

        $this->session_result = "ended_study";
        $this->logResult();

        return array(
            'body' => $body,
        );
    }

}
