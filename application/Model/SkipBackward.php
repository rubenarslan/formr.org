<?php

class SkipBackward extends Branch {

    public $type = 'SkipBackward';
    public $icon = 'fa-backward';

    /**
     * An array of unit's exportable attributes
     * @var array
     */
    public $export_attribs = array('type', 'description', 'position', 'special', 'condition', 'if_true');

    public function displayForRun($prepend = '') {
        $dialog = Template::get($this->getUnitTemplatePath(), array(
                    'prepend' => $prepend,
                    'condition' => $this->condition,
                    'position' => $this->position,
                    'ifTrue' => $this->if_true,
        ));

        return parent::runDialog($dialog);
    }

}
