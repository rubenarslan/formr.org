<?php

class File_Item extends Item {

    public $type = 'file';
    public $input_attributes = array('type' => 'file', 'accept' => "image/*,video/*,audio/*,text/*");
    public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
    public $hasChoices = false;
    protected $file_endings = array(
                // JPEG image files
        'image/jpeg' => '.jpg', // JPEG (Joint Photographic Experts Group), widely used for photos and web images

        // PNG image files
        'image/png' => '.png', // Portable Network Graphics, supports transparency and lossless compression

        // GIF image files
        'image/gif' => '.gif', // Graphics Interchange Format, supports animation and limited color palette

        // TIFF image files
        'image/tiff' => '.tif', // Tagged Image File Format, used for high-quality raster graphics

        // Additional image MIME types
        'image/webp' => '.webp', // WebP format, optimized for web with support for both lossless and lossy compression
        'image/svg+xml' => '.svg', // Scalable Vector Graphics, used for resolution-independent graphics
        'image/heif' => '.heif', // High-Efficiency Image File Format, modern format supporting HDR and compression
        'image/heic' => '.heic', // High-Efficiency Image Coding, specific to Apple devices

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

        // Uncommon or specialized formats
        'video/x-dv' => '.dv', // Digital Video format used in professional video editing
        'video/h265' => '.hevc', // High Efficiency Video Coding (HEVC) format
        'video/h264' => '.h264', // Raw H.264 video stream
        'audio/webm' => '.webm',          // Chrome/Edge
        'audio/mpeg' => '.mp3',           // General audio format
        'video/webm' => '.webm',          // Some browsers may use this for audio blobs
        'audio/mp4' => '.mp4',            // Safari (preferred MIME type)
        'video/mp4' => '.mp4',            // Some browsers may use this for audio blobs
        'audio/aac' => '.aac',            // AAC audio files
        'audio/x-m4a' => '.m4a',          // Safari alternative
        'audio/ogg' => '.ogg',            // For browsers supporting OGG format       

        'text/csv' => '.csv', 'text/css' => '.css', 
        'text/tab-separated-values' => '.tsv', 
        'text/plain' => '.txt',
        'application/pdf' => '.pdf'
    );
    protected $embed_html = '%s';
    protected $max_size = NULL;
    protected $uploaded_file_info = null;

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
                    if (move_uploaded_file($reply['tmp_name'], APPLICATION_ROOT . 'webroot/assets/tmp/user_uploaded_files/' . $new_file_name)) {
                        $public_path = asset_url('tmp/user_uploaded_files/' . $new_file_name, false);
                        
                        // Store file info during validation
                        $this->uploaded_file_info = [
                            'original_filename' => $reply['name'],
                            'stored_path' => $public_path
                        ];
                        
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

    /**
     * Get information about the uploaded file from a validated reply
     * @return array|null Array containing file info or null if no file was uploaded
     */
    public function getFileInfo() {
        return $this->uploaded_file_info;
    }

}
