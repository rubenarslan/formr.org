<?php

class Audio_Item extends File_Item {

    public $type = 'audio';
    public $input_attributes = array('type' => 'file', 'capture' => "user");
    public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
    protected $file_endings = array(
        'audio/webm' => '.webm',          // Chrome/Edge
        'audio/mpeg' => '.mp3',           // General audio format
        'video/webm' => '.webm',          // Some browsers may use this for audio blobs
        'audio/mp4' => '.mp4',            // Safari (preferred MIME type)
        'video/mp4' => '.mp4',            // Some browsers may use this for audio blobs
        'audio/aac' => '.aac',            // AAC audio files
        'audio/x-m4a' => '.m4a',          // Safari alternative
        'audio/ogg' => '.ogg',            // For browsers supporting OGG format
    );
    protected $embed_html = '<audio src="%s" controls>Your browser does not support audio elements.</audio>';
    protected $max_size = 16777219;
    
    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->input_attributes['accept'] = $this->input_attributes['accept'] . ";capture=microphone";
    }
}
