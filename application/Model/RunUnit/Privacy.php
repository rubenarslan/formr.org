<?php

class Privacy extends RunUnit {

    public $type = 'Privacy';
    public $icon = "fa-vcard";

    protected $privacy = '';
    protected $privacy_parsed = '';
    protected $privacy_label = '';
    protected $privacy_label_parsed = '';
    protected $has_tos = 0;
    protected $tos = '';
    protected $tos_parsed = '';
    protected $tos_label = '';
    protected $tos_label_parsed = '';
    protected $imprint = '';
    protected $imprint_parsed = '';

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'privacy', 'privacy_label', 'has_tos', 'tos', 'tos_label', 'imprint');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $cols = 'privacy, privacy_parsed, privacy_label, privacy_label_parsed, has_tos, tos, tos_parsed, tos_label, tos_label_parsed, imprint, imprint_parsed';
            $vars = $this->db->findRow('survey_privacy', array('id' => $this->id), $cols);
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
        $this->privacy_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->text($this->privacy); // transform upon insertion into db instead of at runtime
        $this->privacy_label_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->text($this->privacy_label); // transform upon insertion into db instead of at runtime
        $this->tos_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->text($this->tos); // transform upon insertion into db instead of at runtime
        $this->tos_label_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->text($this->tos_label); // transform upon insertion into db instead of at runtime
        $this->imprint_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->text($this->imprint); // transform upon insertion into db instead of at runtime

        $this->db->insert_update('survey_privacy', array(
            'id' => $this->id,
            'privacy' => $this->privacy,
            'privacy_parsed' => $this->privacy_parsed,
            'privacy_label' => $this->privacy_label,
            'privacy_label_parsed' => $this->privacy_label_parsed,
            'has_tos' => (trim($this->tos) != '') ? 1 : 0,
            'tos' => $this->tos,
            'tos_parsed' => $this->tos_parsed,
            'tos_label' => $this->tos_label,
            'tos_label_parsed' => $this->tos_label_parsed,
            'imprint' => $this->imprint,
            'imprint_parsed' => $this->imprint_parsed,
        ));

        $this->db->commit();
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'privacy' => $this->privacy,
            'privacy_label' => $this->privacy_label,
            'tos' => $this->tos,
            'tos_label' => $this->tos_label,
            'imprint' => $this->imprint,
        ));

        return $this->runDialog($dialog);
    }

    public function removeFromRun($special = null) {
        return $this->delete($special);
    }

    public function test() {
        $template = '
            <label for="%{name}">
                <input type="hidden" name="%{name}" value="0">
                <input type="checkbox" name="%{name}" value="1" required>
                %{label}
            </label>
            <br>
        ';

        $content = Template::replace($template, [
            'name' => 'privacy',
            'label' => $this->prepareLabel($this->privacy_label_parsed),
        ]);
        if ($this->has_tos) {
            $content .= Template::replace($template, [
                'name' => 'tos',
                'label' => $this->prepareLabel($this->tos_label_parsed),
            ]);
        }
        $content .= '<input type="submit" value="Continue">
            <br>';
        return $content;
    }

    public function find($id, $special = false, $props = []) {
        parent::find($id, $special, $props);
        $this->type = 'Privacy';

        return $this;
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        $output = [];

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!empty($_POST['privacy']) && !($this->has_tos && empty($_POST['tos']))) {
                if ($_POST['privacy'] == '1' && !($this->has_tos && $_POST['tos'] == '0')) {
                    // TODO: Save privacy: 1, tos: $this->has_tos ? 1 : 0
                    $output['end_session'] = true;
                    $output['move_on'] = true;
                    $output['log'] = $this->getLogMessage('privacy_tos_accepted');
                    return $output;
                } else {
                    alert('There was an error in your request. Please retry after ticking the required boxes.');
                    $output['log'] = $this->getLogMessage('privacy_tos_rejected');
                }
            } else {
                alert('There was an error in your request. Please try again.');
                $output['log'] = $this->getLogMessage('privacy_tos_request_incomplete');
            }
        }

        $output['content'] = '<form action="" method="post">';

        $template = '
            <label for="%{name}">
                <input type="hidden" name="%{name}" value="0">
                <input type="checkbox" name="%{name}" value="1" required>
                %{label}
            </label>
            <br>';

        $output['content'] .= Template::replace($template, [
            'name' => 'privacy',
            'label' => $this->prepareLabel($this->privacy_label_parsed),
        ]);

        if ($this->has_tos) {
            $output['content'] .= Template::replace($template, [
                'name' => 'tos',
                'label' => $this->prepareLabel($this->tos_label_parsed),
            ]);
        }

        $output['content'] .= '<input type="submit" value="Continue">
            </form>
            <br>';

        return $output;
    }

    private function prepareLabel($label) {
        $run_url = run_url($this->run->name) . '?show-privacy-page=';
        $label = preg_match('/<p>([\s\S]*)<\/p>/', $label, $matches) ? $matches[1] : $label;
        $label = str_replace('}"', '}" target="_blank"', $label);
        return str_replace(array('{privacy-url}', '{tos-url}'), array($run_url . 'Privacy', $run_url . 'ToS'), $label);
    }
}
