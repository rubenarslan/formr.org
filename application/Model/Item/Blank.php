<?php

class Blank_Item extends Text_Item {

    public $type = 'blank';
    public $mysql_field = 'TEXT DEFAULT NULL';

    public function render() {
        if ($this->error) {
            $this->classes_wrapper[] = "has-error";
        }
        $template = '<div class="%{classes_wrapper}" %{showif}>%{text}</div>';

        return Template::replace($template, array(
                    'classes_wrapper' => implode(' ', $this->classes_wrapper),
                    'showif' => $this->data_showif ? sprintf('data-showif="%s"', h($this->js_showif)) : '',
                    'text' => $this->label_parsed,
        ));
    }

}
