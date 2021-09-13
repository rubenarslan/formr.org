<?php


/**
 * Parse survey study items from a JSON file
 *
 * @author ctata
 */
class JsonReader {
    
    public $parsedown;
    
    public function __construct() {
        $this->parsedown = new ParsedownExtra();
        $this->parsedown = $this->parsedown->setBreaksEnabled(true)->setUrlsLinked(true);
    }
    
    public function readItemTableFile($filepath) {
        
            $data = @json_decode(file_get_contents($target));
            $SPR = $this->createFromData($data, true);
    }
}
