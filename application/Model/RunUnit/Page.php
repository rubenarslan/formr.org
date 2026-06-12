<?php

class Page extends RunUnit {

    public $title;
    public $type = 'Endpage';
    public $icon = "fa-stop";

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'body', 'title');

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
        $parsedown->setBreaksEnabled(true);
        // transform upon insertion into db instead of at runtime
        $this->body_parsed = parsedown_text_safe($parsedown, $this->body, 'the page body');

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
        if (($testSession = $this->getTestSession($this->body)) === false) {
            // knitting needed but no test session to use data
            return;
        }

        return $this->getParsedBody($this->body, $testSession, ['admin' => true]);
    }

    public function render() {
        if (!knitting_needed($this->body)) {
            return $this->body_parsed;
        }

        $ocpu = opencpu_knit_iframe($this->body, array(), true, null, $this->run->description, $this->run->footer_text);

        // On error: return the failure as inline HTML where the iframe
        // would have been. The overview template renders alerts BEFORE
        // calling render(), so an alert added here would land in a
        // buffer that's already been flushed — the admin would see a
        // blank Overview box with no indication the script failed.
        // Inlining the OpenCPU debugger keeps the error visible at
        // exactly the spot the broken output should have rendered.
        if (empty($ocpu)) {
            return '<div class="alert alert-danger">'
                . '<strong>OpenCPU is unreachable.</strong> '
                . 'The R runtime didn\'t respond — usually a temporary issue. Retry in a few minutes.'
                . '</div>';
        } elseif ($ocpu->hasError()) {
            // Also push the error into the alert pipeline so other
            // observers (logs, future template orderings) pick it up.
            notify_user_error(opencpu_debug($ocpu), 'Overview script: computational error.');
            return '<div class="alert alert-danger">'
                . '<strong>Overview script: computational error.</strong> '
                . 'The R code in this Overview script failed. Details from OpenCPU are below; '
                . 'you can also edit the script via '
                . '<a href="' . h(admin_run_url($this->run->name, 'settings')) . '#overview">Run settings &rarr; Overview script</a>.'
                . '</div>'
                . '<details open class="opencpu-overview-error" style="margin-top:1em;">'
                . '<summary>OpenCPU debugger</summary>'
                . opencpu_debug($ocpu)
                . '</details>';
        }

        print_hidden_opencpu_debug_message($ocpu, "OpenCPU debugger for overview script at position {$this->position}.");
        $files = $ocpu->getFiles('knit.html');

        return '<div class="rmarkdown_iframe">
            <iframe src="' . $files['knit.html'] . '">
              <p>Your browser does not support iframes.</p>
            </iframe>
        </div>';
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

            notify_study_admin($unitSession, 'Page unit: OpenCPU error while knitting page content.', 'error');
            return $output;
        }
        
        $output['content'] = do_run_shortcodes($this->body_parsed, $unitSession->runSession->getRun()->name, $unitSession->runSession->session);
        $output['end_session'] = true;
        $output['end_run_session'] = true;
        $output['log'] = $this->getLogMessage('ended');
        
        return $output;
    }

}
