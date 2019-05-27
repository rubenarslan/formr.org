<?php

class SelectOrAddMultiple_Item extends SelectOrAddOne_Item {

    public $type = 'select_or_add_multiple';
    public $mysql_field = 'TEXT DEFAULT NULL';
    public $input_attributes = array('type' => 'text');

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->text_choices = true;
        $this->input_attributes['data-select2multiple'] = 1;
    }

    public function getReply($reply) {
        if (is_array($reply)) {
            $reply = implode("\n", array_filter($reply));
        }
        return $reply;
    }

    protected function chooseResultFieldBasedOnChoices() {
        
    }

}
