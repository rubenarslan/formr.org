<?php


/**
 * Base Model Class
 *
 * @author ctata
 */
class Model {
    
    public $id = null;

    protected $table = null;
 
    /**
     * 
     * @var DB
     */
    protected $db;
    
    public $valid = false;
    
    protected $cron = false;


    /**
     * 
     * @var array
     */
    public $errors = [];
    public $messages = [];
    public $warnings = [];

    public function __construct() {
        $this->boot();
    }
    
    public function boot() {
        $this->db = DB::getInstance();
        $this->cron = Site::runningInConsole();
    }
    
    protected function assignProperties(array $props) {
        foreach ($props as $prop => $value) {
            if (property_exists($this, $prop)) {
                $this->{$prop} = $value;
            }
        }
        
        return $props;
    }
    
    public function save() {
        $data = $this->toArray();
        if ($data) {
            if ($this->db->entry_exists($this->table, ['id' => $this->id])) {
                return $this->db->update($this->table, $data, ['id' => $this->id]);
            } else {
                return $this->db->insert($this->table, $data);
            }
        }
    }
    
    public function update($data) {
        $this->assignProperties($data);
        $this->save();
    }


    protected function toArray() {
        return [];
    }
    
    public function getDbConnection() {
        return $this->db;
    }
    
    public function isCron() {
        return $this->cron;
    }
}
