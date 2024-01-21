<?php

class Privacy extends RunUnit {

    public $type = 'Privacy';
    public $icon = "fa-vcard";
    protected $label = '';

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'body', 'label');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $vars = $this->db->findRow('survey_privacy', array('id' => $this->id), 'body, body_parsed, label');
            if ($vars) {
                array_walk($vars, "emptyNull");
                $vars['valid'] = true;
                $this->assignProperties($vars);
            }
        }
    }

    public function create($options = []) {
        $this->db->beginTransaction();
        parent::create($options);

        $parsedown = new ParsedownExtra();
        $this->body_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->text($this->body); // transform upon insertion into db instead of at runtime

        $this->db->insert_update('survey_privacy', array(
            'id' => $this->id,
            'body' => $this->body,
            'body_parsed' => $this->body_parsed,
            'label' => $this->label,
        ));

        $this->db->commit();
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'body' => $this->body,
            'label' => $this->label,
        ));

        return $this->runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    public function test() {
        $template = '
            %{body}
            <br>
            <label for="privacy">
                <input type="hidden" name="privacy" value="0">
                <input type="checkbox" name="privacy" value="1" required>
                %{label}
            </label>
            <br>
            <input type="submit" value="Continue">
            <br>
        ';

        if (($testSession = $this->getTestSession($this->body)) === false) {
            // knitting needed but no test session to use data
            return;
        }

        $this->body_parsed = $this->getParsedBody($this->body, $testSession, ['admin' => true]);

        return Template::replace($template, [
            'body' => $this->body_parsed,
            'label' => $this->label,
        ]);
    }

    public function find($id, $special = false, $props = []) {
        parent::find($id, $special, $props);
        $this->type = 'Privacy';

        return $this;
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $output = [];

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!empty($_POST['privacy'])) {
                if ($_POST['privacy'] == '1') {
                    // TODO: Set privacy to true
                    $output['end_session'] = true;
                    $output['move_on'] = true;
                    $output['log'] = $this->getLogMessage('privacy_accepted');
                } else {
                    // TODO: Show error message
                    $output['log'] = $this->getLogMessage('privacy_rejected');
                }
            } else {
                // TODO: Show error message
                $output['log'] = $this->getLogMessage('privacy_error');
            }
        }

        $this->body_parsed = $this->getParsedBody($this->body, $unitSession);

        if ($this->body_parsed === false) {
            $output['wait_opencpu'] = true; // wait for openCPU to be fixed!
            $output['log'] = array_val($this->errors, 'log', []);
            return $output;
        }

        $template = '
            %{body}
            <br>
            <form action="" method="post">
                <label for="privacy">
                    <input type="hidden" name="privacy" value="0">
                    <input type="checkbox" name="privacy" value="1" required>
                    %{label}
                </label>
                <br>
                <input type="submit" value="Continue">
            </form>
            <br>
        ';

        $output['content'] = Template::replace($template, [
            'body' => $this->body_parsed,
            'label' => $this->label,
        ]);

        return $output;
    }
}
