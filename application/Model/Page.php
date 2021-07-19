<?php

class Page extends RunUnit {

    public $errors = array();
    public $id = null;
    public $session = null;
    public $unit = null;
    protected $body = '';
    protected $body_parsed = '';
    public $title = '';
    private $can_be_ended = 0;
    public $ended = false;
    public $type = 'Endpage';
    public $icon = "fa-stop";

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'body');

    public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
        parent::__construct($fdb, $session, $unit, $run_session, $run);

        if ($this->id):
            $vars = $this->dbh->findRow('survey_pages', array('id' => $this->id), 'title, body, body_parsed');
            if ($vars):
                $this->body = $vars['body'];
                $this->body_parsed = $vars['body_parsed'];
                $this->title = $vars['title'];
#				$this->can_be_ended = $vars['end'] ? 1:0;
                $this->can_be_ended = 0;

                $this->valid = true;
            endif;
        endif;

        if (!empty($_POST) AND isset($_POST['page_submit'])) {
            unset($_POST['page_submit']);
            $this->end();
        }
    }

    public function create($options) {
        if (!$this->id) {
            $this->id = parent::create('Page');
        } else {
            $this->modify($options);
        }

        if (isset($options['body'])) {
            $this->body = $options['body'];
//			$this->title = $options['title'];
//			$this->can_be_ended = $options['end'] ? 1:0;
            $this->can_be_ended = 0;
        }

        $parsedown = new ParsedownExtra();
        $this->body_parsed = $parsedown
                ->setBreaksEnabled(true)
                ->text($this->body); // transform upon insertion into db instead of at runtime

        $this->dbh->insert_update('survey_pages', array(
            'id' => $this->id,
            'body' => $this->body,
            'body_parsed' => $this->body_parsed,
            'title' => $this->title,
            'end' => (int) $this->can_be_ended,
        ));
        $this->valid = true;

        return true;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getUnitTemplatePath(), array(
                    'prepend' => $prepend,
                    'body' => $this->body,
        ));

        return parent::runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    public function test() {
        return $this->getParsedBodyAdmin($this->body);
    }

    public function exec() {
        if ($this->called_by_cron) {
            $this->getParsedBody($this->body); // make report before showing it to the user, so they don't have to wait
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

        $this->session_result = "ended";
        $this->logResult();

        return array(
            'body' => $body,
        );
    }

}
