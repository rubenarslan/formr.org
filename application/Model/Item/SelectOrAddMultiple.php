<?php

class SelectOrAddMultiple_Item extends SelectOrAddOne_Item {
    protected $accepts_array_reply = true;


    public $type = 'select_or_add_multiple';
    public $mysql_field = 'TEXT DEFAULT NULL';
    public $input_attributes = array('type' => 'text');
    public $text_choices;

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->text_choices = true;
        $this->input_attributes['data-select2multiple'] = 1;
    }

    public function getReply($reply) {
        if (is_array($reply)) {
            $reply = implode("\n", $this->replyValues($reply));
        }
        return $reply;
    }

    protected function chooseResultFieldBasedOnChoices() {

    }

    /**
     * One text input carries the whole answer (entries joined by ";" in v2,
     * by line breaks from v1), so the state block gets the stored string as
     * is — not split at ", ", which would cut an entry like "Berlin, Germany".
     */
    public function storedValueForClient($answer) {
        return $answer;
    }

}
