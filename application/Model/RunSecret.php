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

    /**
     * Secret names become R code verbatim (.formr$secret_<name> = '…'),
     * so they must be safe R identifier suffixes. Same pattern is
     * enforced client-side in run_settings.js — keep in sync.
     */
    const NAME_PATTERN = '/^[A-Za-z0-9_]{1,190}$/';

    public static function isValidName($name)
    {
        return is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Names only — no decryption. Use wherever the values themselves
     * are not needed (settings page render, run export).
     */
    public static function getSecretNamesForRun($run_id)
    {
        $db = DB::getInstance();
        return $db->select(array('name'))
            ->from('survey_run_secrets')
            ->where(array('run_id' => $run_id))
            ->order('name')
            ->statement()
            ->fetchAll(PDO::FETCH_COLUMN, 0);
    }

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

    /**
     * Reconcile the run's secrets with the posted map.
     *
     * Write-only protocol matching the admin UI (values are never sent
     * back to the browser): $secrets maps name => value where
     *   - a string value creates or replaces the secret (empty string is
     *     a valid stored value — import placeholders rely on this),
     *   - null means "keep the existing value unchanged",
     *   - a name absent from the map is deleted.
     * Names not matching NAME_PATTERN are ignored entirely (neither
     * saved nor counted as kept, so an invalid name can't block the
     * deletion pass).
     */
    public static function setSecretsForRun($run_id, array $secrets)
    {
        $db = DB::getInstance();
        $now = mysql_now();

        $existing = self::getSecretNamesForRun($run_id);

        $seen = array();

        foreach ($secrets as $name => $value) {
            $name = trim((string) $name);
            if (!self::isValidName($name)) {
                continue;
            }

            $seen[] = $name;

            if ($value === null) {
                continue; // unchanged — admin did not retype this secret
            }

            $encrypted = Crypto::encrypt((string) $value);
            if ($encrypted === null) {
                continue;
            }

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

        $existing = self::getSecretNamesForRun($run_id);

        foreach ($names as $name) {
            $name = trim((string) $name);
            if (!self::isValidName($name)) {
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
