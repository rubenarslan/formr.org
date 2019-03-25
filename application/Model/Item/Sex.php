<?php

class Sex_Item extends McButton_Item {

    public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->setChoices(array());
    }

    public function setChoices($choices) {
        $this->choices = array(1 => '♂', 2 => '♀');
    }

}
