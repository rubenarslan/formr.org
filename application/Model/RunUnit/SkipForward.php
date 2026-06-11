<?php

class SkipForward extends Branch {

    public $type = 'SkipForward';
    public $icon = 'fa-forward';

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'condition', 'if_true', 'automatically_jump', 'automatically_go_on');

}
