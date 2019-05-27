<?php

class ChooseTwoWeekdays_Item extends McMultiple_Item {

    protected function setMoreOptions() {
        $this->optional = 0;
        $this->classes_input[] = 'choose2days';
        $this->input_attributes['name'] = $this->name . '[]';
    }

}
