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

    /**
     * In v2 the answer is the chosen entries joined by ";" (the form's
     * tom-select delimiter, items/tom-select.js), so a choice containing one
     * would come back as two entries. v1 joins by commas and keeps them. A
     * list written in one cell is separated by ";" in v2 (unpackChoices),
     * so it is checked as the choices it stands for.
     */
    public function formV2Unsupported() {
        if ($why = parent::formV2Unsupported()) {
            return $why;
        }
        foreach (self::unpackChoices((array) $this->choices, true) as $choice) {
            if (strpos((string) $choice, ';') !== false) {
                return "'{$this->name}': in v2, the answers of a select_or_add_multiple item are separated by semicolons, so its choices cannot contain one (\"{$choice}\"). Remove the semicolon from the choice.";
            }
        }
        return null;
    }

}
