<?php

class Image_Item extends File_Item {

	public $type = 'image';
	public $input_attributes = array('type' => 'file', 'accept' => "image/*;capture=camera");
	public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
	protected $file_endings = array('image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/tiff' => '.tif');
	protected $embed_html = '<img src="%s">';
	protected $max_size = 16777219;

}
