<?php

class Get_Item extends Item {

    public $type = 'get';
    public $input_attributes = array('type' => 'hidden');
    public $no_user_input_required = true;
    public $probably_render = true;
    public $mysql_field = 'TEXT DEFAULT NULL';
    protected $hasChoices = false;
    private $get_var = 'referred_by';

    protected function setMoreOptions() {
        if (isset($this->type_options_array) && is_array($this->type_options_array)) {
            if (count($this->type_options_array) == 1) {
                $this->get_var = trim(current($this->type_options_array));
            }
        }

        $this->input_attributes['value'] = '';
        $request = new Request($_GET);
        $value = $request->getParam($this->get_var);
        if ($value !== null && $value !== "") {
            $this->input_attributes['value'] = $value;
            $this->value = $value;
            $this->value_validated = $value;
        }
    }

    public function validate() {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->get_var)) {
            $this->val_errors[] = __('Problem with variable %s "get %s". The part after get can only contain a-Z0-9 and the underscore.', $this->name, $this->get_var);
        }
        return parent::validate();
    }

    public function validateInput($reply) {
        $this->reply = $reply;
        if (!$this->optional && (($reply === null || $reply === false || $reply === array() || $reply === '') || (is_array($reply) && count($reply) === 1 && current($reply) === ''))) {
            // missed a required field
            $this->error = 'error (missing GET param): ' . $this->label_parsed;
            $reply = null;
        } elseif ($this->optional && $reply == '') {
            $reply = null;
        }
        
        return $reply;
    }

    public function render() {
        return $this->render_input();
    }

    public function needsDynamicValue() {
        return false;
    }

}
