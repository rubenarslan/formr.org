<?php

class Video_Item extends File_Item {

    public $type = 'video';
    public $input_attributes = array('type' => 'file', 'capture' => "user");
    public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
    protected $file_endings = array(
        // MPEG video files
        'video/mpeg' => '.mpg', // MPEG-1 or MPEG-2 video file

        // MP4 video files
        'video/mp4' => '.mp4', // MPEG-4 Part 14, widely used for streaming and online videos

        // QuickTime video files
        'video/quicktime' => '.mov', // Apple QuickTime video format

        // FLV video files
        'video/x-flv' => '.flv', // Adobe Flash Video file

        // F4V video files
        'video/x-f4v' => '.f4v', // Flash Video file with H.264 encoding

        // AVI video files
        'video/x-msvideo' => '.avi', // Audio Video Interleave, commonly used for high-quality video

        // Additional video MIME types
        'video/webm' => '.webm', // WebM video format optimized for web delivery
        'video/3gpp' => '.3gp', // 3GPP multimedia file, common for mobile devices
        'video/3gpp2' => '.3g2', // 3GPP2 multimedia file, variant for CDMA devices
        'video/x-ms-wmv' => '.wmv', // Windows Media Video file
        'video/ogg' => '.ogv', // Ogg video format, often used with free codecs like Theora
    );
    protected $embed_html = '<video src="%s" controls>Your browser does not support video elements.</audio>';
    protected $max_size = 16777219;
    
    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->input_attributes['accept'] = $this->input_attributes['accept'] . ";capture=camcorder";
    }
}
