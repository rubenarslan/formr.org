<?php

class Image_Item extends File_Item {

    public $type = 'image';
    public $input_attributes = array('type' => 'file', 'capture' => "user");
    public $mysql_field = 'VARCHAR(1000) DEFAULT NULL';
    protected $file_endings = [
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
                
    ];
    protected $embed_html = '<img src="%s">';
    protected $max_size = 16777219;

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->input_attributes['accept'] = $this->input_attributes['accept'] . ";capture=camera";
    }
}
