<?php

class RunSecret extends Model
{
    public $id = null;
    public $run_id = null;
    public $name = null;
    public $value_encrypted = null;
    public $created = null;
    public $modified = null;

    protected $table = 'survey_run_secrets';

    public static function getSecretsForRun($run_id)
    {
        $db = DB::getInstance();
        $rows = $db->select(array('name', 'value_encrypted'))
            ->from('survey_run_secrets')
            ->where(array('run_id' => $run_id))
            ->statement()
            ->fetchAll(PDO::FETCH_ASSOC);

        $secrets = array();
        foreach ($rows as $row) {
            $decrypted = Crypto::decrypt($row['value_encrypted']);
            if ($decrypted !== null) {
                $secrets[$row['name']] = $decrypted;
            }
        }

        return $secrets;
    }

    public static function setSecretsForRun($run_id, array $secrets)
    {
        $db = DB::getInstance();
        $now = mysql_now();

        $existing = $db->select(array('name'))
            ->from('survey_run_secrets')
            ->where(array('run_id' => $run_id))
            ->statement()
            ->fetchAll(PDO::FETCH_COLUMN, 0);

        $seen = array();

        foreach ($secrets as $name => $value) {
            $name = trim($name);
            if ($name === '' || $value === '') {
                continue;
            }

            $encrypted = Crypto::encrypt($value);
            if ($encrypted === null) {
                continue;
            }

            $seen[] = $name;

            if (in_array($name, $existing)) {
                $db->update('survey_run_secrets', array(
                    'value_encrypted' => $encrypted,
                    'modified' => $now,
                ), array('run_id' => $run_id, 'name' => $name));
            } else {
                $db->insert('survey_run_secrets', array(
                    'run_id' => $run_id,
                    'name' => $name,
                    'value_encrypted' => $encrypted,
                    'created' => $now,
                    'modified' => $now,
                ));
            }
        }

        $to_delete = array_diff($existing, $seen);
        foreach ($to_delete as $name) {
            $db->delete('survey_run_secrets', array('run_id' => $run_id, 'name' => $name));
        }
    }

    public static function ensureSecretsExist($run_id, array $names)
    {
        $db = DB::getInstance();
        $now = mysql_now();

        $existing = $db->select(array('name'))
            ->from('survey_run_secrets')
            ->where(array('run_id' => $run_id))
            ->statement()
            ->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            if (!in_array($name, $existing)) {
                $encrypted = Crypto::encrypt('');
                if ($encrypted !== null) {
                    $db->insert('survey_run_secrets', array(
                        'run_id' => $run_id,
                        'name' => $name,
                        'value_encrypted' => $encrypted,
                        'created' => $now,
                        'modified' => $now,
                    ));
                }
            }
        }
    }
}
