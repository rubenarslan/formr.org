<?php

class Audio_Item extends File_Item {

    public $type = 'audio';
    public $input_attributes = array('type' => 'file', 'capture' => "user");
    public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
    protected $file_endings = array('image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif');
    protected $embed_html = '<img src="%s">';
    protected $max_size = 16777219;
    
    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->input_attributes['accept'] = $this->input_attributes['accept'] . ";capture=microphone";
    }
}
