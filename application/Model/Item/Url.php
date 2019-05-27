<?php

class Url_Item extends Text_Item {

    public $type = 'url';
    public $input_attributes = array('type' => 'url');
    public $mysql_field = 'VARCHAR(255) DEFAULT NULL';
    protected $prepend = 'fa-link';

    public function validateInput($reply) {
        if ($this->optional && trim($reply) == ''):
            return parent::validateInput($reply);
        else:
            $reply_valid = filter_var($reply, FILTER_VALIDATE_URL);
            if (!$reply_valid):
                $this->error = __('The URL %s is not valid', h($reply));
            endif;
        endif;
        return $reply_valid;
    }

    protected function setMoreOptions() {
        $this->classes_input[] = 'form-control';
    }

}
