<?php

class File_Item extends Item {

    public $type = 'file';
    public $input_attributes = array('type' => 'file', 'accept' => "image/*,video/*,audio/*,text/*");
    public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
    protected $file_endings = array(
        'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif',
        'video/mpeg' => '.mpg', 'video/mp4' => '.mp4', 'video/quicktime' => '.mov', 'video/x-flv' => '.flv', 'video/x-f4v' => '.f4v', 'video/x-msvideo' => '.avi',
        'audio/mpeg' => '.mp3',
        'text/csv' => '.csv', 'text/css' => '.css', 'text/tab-separated-values' => '.tsv', 'text/plain' => '.txt'
    );
    protected $embed_html = '%s';
    protected $max_size = NULL;

    protected function setMoreOptions() {
        if (is_array($this->type_options_array) && count($this->type_options_array) == 1) {
            $val = (int) trim(current($this->type_options_array));
            if (is_numeric($val)) {
                $bytes = $val * 1048576; # size is provided in MB
                $this->max_size = $bytes;
            }
        }
        $post_max_size = convertToBytes(ini_get('post_max_size'));
        $upload_max_filesize = convertToBytes(ini_get('upload_max_filesize'));
        $max_size = max($post_max_size, $upload_max_filesize);
        if($this->max_size === NULL || $this->max_size > $max_size) {
            $this->max_size = $max_size;
        }

        $this->input_attributes['data-max-size'] = $this->max_size;
        $this->input_attributes['accept'] = implode(',', array_keys($this->file_endings));
    }

    public function validateInput($reply) {
        $phpFileUploadErrors = array(
            0 => 'There is no error, the file uploaded with success',
            1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            3 => 'The uploaded file was only partially uploaded',
            4 => 'No file was uploaded',
            6 => 'Missing a temporary folder',
            7 => 'Failed to write file to disk.',
            8 => 'A PHP extension stopped the file upload.',
        );
        
        if ($reply['error'] === 0) { // verify maximum length and no errors
            if (filesize($reply['tmp_name']) < $this->max_size) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($reply['tmp_name']);
                if (!in_array($mime, array_keys($this->file_endings))) {
                    $this->error = 'Files of type ' . $mime . ' are not allowed to be uploaded.';
                } else {
                    $new_file_name = crypto_token(66) . $this->file_endings[$mime];
                    if (move_uploaded_file($reply['tmp_name'], APPLICATION_ROOT . 'webroot/assets/tmp/' . $new_file_name)) {
                        $public_path = WEBROOT . 'assets/tmp/' . $new_file_name;
                        $reply = __($this->embed_html, $public_path);
                    } else {
                        $this->error = __("Unable to save uploaded file");
                    }
                }
            } else {
                $this->error = __("This file is too big the maximum is %d megabytes.", round($this->max_size / 1048576, 2));
                $reply = null;
            }
        } elseif ($reply['error'] === 4 && $this->optional) {
            $reply = null;
        } else {
            $this->error = __("Error uploading file. (Code: %s)", $phpFileUploadErrors[$reply['error']]);
            $reply = null;
        }
        $this->reply = parent::validateInput($reply);
        return $this->reply;
    }

    public function getReply($reply) {
        return $this->reply;
    }

}
