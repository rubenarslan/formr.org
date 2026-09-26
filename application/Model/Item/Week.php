<?php

class Week_Item extends Datetime_Item {

    public $type = 'week';
    public $input_attributes = array('type' => 'week');
    public $mysql_field = 'VARCHAR(9) DEFAULT NULL';
    // The HTML week value ("2025-W01"): ISO year + ISO week. 'Y-mW' stored
    // calendar year + month + week ("2024-1201" for 2025-W01 — the wrong
    // year whenever week 1 starts in December) and never round-tripped into
    // the input.
    protected $html5_date_format = 'o-\\WW';

    //protected $prepend = 'fa-calendar-o';
}
