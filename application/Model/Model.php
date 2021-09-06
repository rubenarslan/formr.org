<?php


/**
 * Base Model Class
 *
 * @author ctata
 */
class Model {
    
    /**
     * 
     * @var DB
     */
    protected $db;
    
    public $valid = false;

    public function __construct() {
        $this->boot();
    }
    
    public function boot() {
        $this->db = DB::getInstance();
    }
    
    protected function assignProperties(array $props) {
        foreach ($props as $prop => $value) {
            if (property_exists($this, $prop)) {
                $this->{$prop} = $value;
            }
        }
    }
}
