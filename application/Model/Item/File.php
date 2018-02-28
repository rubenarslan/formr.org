<?php

class File_Item extends Item {

	public $type = 'file';
	public $input_attributes = array('type' => 'file', 'accept' => "image/*,video/*,audio/*,text/*;capture=camera");
	public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
	protected $file_endings = array(
		'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif',
		'video/mpeg' => '.mpg', 'video/quicktime' => '.mov', 'video/x-flv' => '.flv', 'video/x-f4v' => '.f4v', 'video/x-msvideo' => '.avi',
		'audio/mpeg' => '.mp3',
		'text/csv' => '.csv', 'text/css' => '.css', 'text/tab-separated-values' => '.tsv', 'text/plain' => '.txt'
	);
	protected $embed_html = '%s';
	protected $max_size = 16777219;

	protected function setMoreOptions() {
		if (is_array($this->type_options_array) && count($this->type_options_array) == 1) {
			$val = (int) trim(current($this->type_options_array));
			if (is_numeric($val)) {
				$bytes = $val * 1048576; # size is provided in MB
				$this->max_size = $bytes;
			}
		}
	}

	public function validateInput($reply) {
		if ($reply['error'] === 0) { // verify maximum length and no errors
			if (filesize($reply['tmp_name']) < $this->max_size) {
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$mime = $finfo->file($reply['tmp_name']);
				$new_file_name = crypto_token(66) . $this->file_endings[$mime];
				if (!in_array($mime, array_keys($this->file_endings))) {
					$this->error = 'Files of type' . $mime . ' are not allowed to be uploaded.';
				} elseif (move_uploaded_file($reply['tmp_name'], APPLICATION_ROOT . 'webroot/assets/tmp/' . $new_file_name)) {
					$public_path = WEBROOT . 'assets/tmp/' . $new_file_name;
					$reply = __($this->embed_html, $public_path);
				} else {
					$this->error = __("Unable to save uploaded file");
				}
			} else {
				$this->error = __("This file is too big the maximum is %d megabytes.", round($this->max_size / 1048576, 2));
				$reply = null;
			}
		} else {
			$this->error = "Error uploading file";
			$reply = null;
		}

		$this->reply = parent::validateInput($reply);
		return $this->reply;
	}

	public function getReply($reply) {
		return $this->reply;
	}

}
