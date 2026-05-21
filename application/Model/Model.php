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
    
    protected function assignProperties($props) {
        if ($props && is_array($props)) {
            foreach ($props as $prop => $value) {
                if (property_exists($this, $prop)) {
                    if ($value === '') {
                        $value = null;
                    }
                    $this->{$prop} = $value;
                }
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
    
    public function delete() {
        $this->db->delete($this->table, ['id' => $this->id]);
    }

    protected function toArray() {
        return [];
    }
    
    public function getDbConnection() {
        return $this->db;
    }

    /**
     * Confirm a row's $owner_col matches an expected user id. The single
     * canonical primitive for FK-relink ownership checks; every code path
     * that re-points a *_id from request input to another row must call
     * this and reject on false.
     *
     * Returns false on missing row, NULL stored owner, or mismatch — never
     * throws, since callers already know how to handle "not yours" by
     * dropping or alerting. See application/Model/RunUnit/Survey.php and
     * application/Model/RunUnit/Email.php for the canonical use sites.
     *
     * @param string $table     e.g. 'survey_studies', 'survey_email_accounts'
     * @param int    $id        FK id supplied by the caller
     * @param int    $user_id   user the caller asserts owns it (run owner
     *                          or current user)
     * @param string $owner_col defaults to 'user_id'
     * @return bool
     */
    public function isOwnedBy($table, $id, $user_id, $owner_col = 'user_id') {
        if (!$id || !$user_id) {
            return false;
        }
        $stored = $this->db->findValue($table, ['id' => (int) $id], $owner_col);
        return $stored !== null && $stored !== false
            && (int) $stored === (int) $user_id;
    }
    
    public function isCron() {
        return $this->cron;
    }

    public function refresh($options) {
        if (!$this->table) {
            return null;
        }

        $row = $this->db->findRow($this->table, $options);
        if ($row) {
            $this->assignProperties($row);
            return $this;
        }
        
        return null;
    }
}
