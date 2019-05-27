<?php

/**
 * Dropdown to select multiple items
 */
class SelectMultiple_Item extends SelectOne_Item {

    public $type = 'select_multiple';
    public $mysql_field = 'VARCHAR(40) DEFAULT NULL';

    protected function chooseResultFieldBasedOnChoices() {
        $choices = array_keys($this->choices);
        $max = implode(", ", array_filter($choices));
        $maxlen = strlen($max);
        $this->mysql_field = 'VARCHAR (' . $maxlen . ') DEFAULT NULL';
    }

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->input_attributes['multiple'] = true;
        $this->input_attributes['name'] = $this->name . '[]';
    }

    public function getReply($reply) {
        if (is_array($reply)) {
            $reply = implode(", ", array_filter($reply));
        }
        return $reply;
    }

}
