<?php

class Privacy extends RunUnit {

    public $type = 'Privacy';
    public $icon = "fa-vcard";
    protected $privacy_label = '';
    protected $privacy_label_parsed = '';
    protected $tos_label = '';
    protected $tos_label_parsed = '';

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'privacy_label', 'tos_label');

    public function __construct(Run $run, array $props = []) {
        parent::__construct($run, $props);

        if ($this->id) {
            $cols = 'privacy_label, privacy_label_parsed, tos_label, tos_label_parsed';
            $vars = $this->db->findRow('survey_privacy', array('id' => $this->id), $cols);
            if ($vars) {
                $vars['valid'] = true;
                $this->assignProperties($vars);
            }
        }
    }

    public function create($options = []) {
        $this->db->beginTransaction();
        parent::create($options);

        $parsedown = new ParsedownExtra();
        $this->privacy_label_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->line($this->privacy_label); // transform upon insertion into db instead of at runtime
        $this->tos_label_parsed = $parsedown
            ->setBreaksEnabled(true)
            ->line($this->tos_label); // transform upon insertion into db instead of at runtime

        $this->db->insert_update('survey_privacy', array(
            'id' => $this->id,
            'privacy_label' => $this->privacy_label,
            'privacy_label_parsed' => $this->privacy_label_parsed,
            'tos_label' => $this->tos_label,
            'tos_label_parsed' => $this->tos_label_parsed,
        ));

        $this->db->commit();
        $this->valid = true;

        return $this;
    }

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getTemplatePath(), array(
            'prepend' => $prepend,
            'run' => $this->run,
            'privacy_label' => $this->privacy_label,
            'tos_label' => $this->tos_label,
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

        $content = '';

        if ($this->run->hasPrivacy()) {
            $content = Template::replace($template, [
                'name' => 'privacy',
                'label' => $this->prepareLabel($this->privacy_label_parsed),
            ]);
        }
        if ($this->run->hasToS()) {
            $content .= Template::replace($template, [
                'name' => 'tos',
                'label' => $this->prepareLabel($this->tos_label_parsed),
            ]);
        }
        if ($this->run->hasPrivacy() || $this->run->hasToS()) {
            $content .= '<input type="submit" value="Continue">
            <br>';
        } else {
            $content .= 'No privacy policy or terms of service set. Please set them in the <a href="' . admin_run_url($this->run->name) . '/settings#privacy">Privacy Settings</a>';
        }
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
            if (!($this->run->hasPrivacy() && empty($_POST['privacy'])) && !($this->run->hasToS() && empty($_POST['tos']))) {
                if (!($this->run->hasPrivacy() && $_POST['privacy'] == '0') && !($this->run->hasToS() && $_POST['tos'] == '0')) {
                    $output['end_session'] = true;
                    $output['move_on'] = true;
                    $message = 'privacy policy: ';
                    $message .= $_POST['privacy'] ? 'accepted' : 'not set';
                    $message .= ', terms of service: ';
                    $message .= $_POST['tos'] ? 'accepted' : 'not set';
                    $output['log'] = $this->getLogMessage('privacy_tos_consent', $message);
                    return $output;
                } else {
                    $message = $this->run->hasPrivacy()? 'privacy policy' : '';
                    $message .= $this->run->hasPrivacy() && $this->run->hasToS()? ' and ' : '';
                    $message .= $this->run->hasToS()? 'terms of service' : '';
                    alert('You must accept the ' . $message . ' to continue.');
                }
            } else {
                $message = $this->run->hasPrivacy()? 'privacy policy' : '';
                $message .= $this->run->hasPrivacy() && $this->run->hasToS()? ' and ' : '';
                $message .= $this->run->hasToS()? 'terms of service' : '';
                alert('You must accept the ' . $message . ' to continue.');
            }
        }

        $template = '
            <label for="%{name}">
                <input type="hidden" name="%{name}" value="0">
                <input type="checkbox" name="%{name}" value="1" required>
                %{label}
            </label>
            <br>';

        $content = '';

        if ($this->run->hasPrivacy()) {
            $content .= Template::replace($template, [
                'name' => 'privacy',
                'label' => $this->prepareLabel($this->privacy_label_parsed),
            ]);
        }

        if ($this->run->hasToS()) {
            $content .= Template::replace($template, [
                'name' => 'tos',
                'label' => $this->prepareLabel($this->tos_label_parsed),
            ]);
        }

        if ($this->run->hasPrivacy() || $this->run->hasToS()) {
            $output['content'] = '<form action="" method="post">';
            $output['content'] .= $content;
            $output['content'] .= '<input type="submit" value="Continue">
            </form>
            <br>';
        } else {
            alert('The survey administrator forgot to set a privacy policy or terms of service. Please contact them about this issue.', 'alert-danger');
        }

        return $output;
    }

    private function prepareLabel($label) {
        $run_url = run_url($this->run->name) . '?show-privacy-page=';
        $label = str_replace('}"', '}" target="_blank"', $label);
        return str_replace(array('{privacy-url}', '{tos-url}'), array($run_url . 'privacy-policy', $run_url . 'terms-of-service'), $label);
    }
}
