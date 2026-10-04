<?php

//fixme:
// attack vector against unlinked surveys
// - delete user data one by one, see which one disappears
// - calculate survey_run_sessions$session in the unlinked surveys
// - calculate private data from unlinked survey in linked survey
// - check user_overview to see which users have already made it to the unlinked survey
class SurveyStudy extends Model
{

    public $id = null;
    public $user_id = null;
    public $name = null;
    public $results_table = null;
    public $valid = null;

    // Settings
    public $maximum_number_displayed = 0;
    public $displayed_percentage_maximum = 100;
    public $add_percentage_points = 0;
    public $expire_after = 0;
    public $expire_invitation_after = 0;
    public $expire_invitation_grace = 0;
    public $enable_instant_validation = 0;
    public $original_file = '';
    public $google_file_id = '';
    public $unlinked = 0;
    public $hide_results = 0;
    public $use_paging = 0;
    public $rendering_mode = 'v1';
    public $offline_mode = 1;
    public $allow_previous = 0;
    public $layout = 'default';
    public $language = 'en'; // BCP 47; drives v2 engine-chrome i18n (patch 114)
    // v2 navigation wording, NULL = built-in per language (patch 115, #322)
    public $next_button_label = null;
    public $submit_button_label = null;
    public $last_iteration = 0; // per-study counter for survey_unit_sessions.study_iteration; patch 108

    public $created = null;
    public $modified = null;

    /**
     * The one rule for a survey name: a bare identifier — a letter, then
     * letters, digits or underscores, 3 to 65 characters in all. The name
     * is a results-table name, a URL segment and a field of the survey-test
     * token (SurveyTestController joins it with `|`), so every path that
     * accepts a name (upload, Google-sheet import, rename, test link) asks
     * isValidName() rather than carrying its own copy of the pattern —
     * four copies had drifted into three different rules.
     */
    const NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]{2,64}\z/';

    public static function isValidName($name) {
        return is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    protected $table = 'survey_studies';

    private $result_count = null;

    protected $user_defined_columns = [
        'name',
        'label',
        'label_parsed',
        'type',
        'type_options',
        'choice_list',
        'optional',
        'class',
        'showif',
        'value',
        'block_order',
        'item_order',
        'order',
        // study_id is not among the user_defined columns
    ];

    protected $can_delete = false;
    protected $is_new = false;


    /**
     * You can initiate a survey by using the ID or passing a couple of options to be used in finding the survey
     *
     * @param int $id
     * @param array $options
     */
    public function __construct($id = null, $options = [])
    {
        parent::__construct();
        // Defense-in-depth allowlist. The loadByUserAndName factory
        // passes {name, user_id} as lookup keys; load()
        // also takes 'id' from these. Everything else (results_table,
        // valid, created, ...) must come from the DB row that load()
        // resolves, not from caller-provided $options.
        if ($options) {
            $this->assignProperties(array_intersect_key(
                (array) $options,
                ['name' => true, 'user_id' => true, 'id' => true]
            ));
        }
        $this->load($id, $options);
    }

    /**
     * Get survey by id
     *
     * @param int $id
     * @return SurveyStudy
     */
    public static function loadById($id)
    {
        return new SurveyStudy((int) $id);
    }

    /**
     * Get survey by name
     *
     * @param string $name
     * @return SurveyStudy
     */
    /**
     * 
     * @param User $user
     * @param string $name
     * @return \SurveyStudy
     */
    public static function loadByUserAndName(User $user, $name)
    {
        $options = [
            'name' => $name,
            'user_id' => $user->id,
        ];
        return new SurveyStudy(null, $options);
    }

    protected function load($id, $options)
    {
        if (!$options || !is_array($options)) {
            $options = [];
        }

        if ($id) {
            $options['id'] = (int) $id;
        }

        if ($options && ($vars = $this->db->findRow('survey_studies', $options))) {
            $this->assignProperties($vars);
            if (!$this->results_table) {
                $this->results_table = $this->name;
            }

            $this->valid = true;
        }
    }

    /**
     * 
     * @param array $file
     *
     * @return boolean
     */

    public function createFromFile($file, $options = [])
    {
        // Create the corresponding entry in survey_units to get the ID. A
        // run in $options (a run-bundle import) is the one the unit is placed
        // in; it must go to the constructor — RunUnit::create refuses `run`
        // from its props (UNASSIGNABLE_PROPS), so on an empty Run the unit
        // was created but never placed.
        $run = (isset($options['run']) && $options['run'] instanceof Run) ? $options['run'] : new Run();
        $id = RunUnitFactory::make($run, ['type' => 'Survey'])->create($options)->id;
        $this->assignProperties($file);

        $this->id = $id;
        $this->name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,64})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/", "$1", basename($file['name']));
        $this->results_table = substr("s" . $this->id . '_' . $this->name, 0, 64);
        $this->created = mysql_now();
        $this->modified = mysql_now();

        // Survey ownership is bound to the caller. Honor an explicit
        // $options['user_id'] only when it agrees with the current user;
        // never blindly accept a caller-supplied id (e.g. via the
        // structure-import JSON consumed by Run::importUnits).
        $current_user_id = (int) Site::getCurrentUser()->id;
        if (isset($options['user_id']) && (int) $options['user_id'] !== $current_user_id) {
            return false;
        }
        $this->user_id = $current_user_id;

        $results_table = substr("s" . $this->id . '_' . $this->name, 0, 64);

        if ($this->db->table_exists($results_table)) {
            alert("Results table name conflict. This shouldn't happen. Please alert the formr admins.", 'alert-danger');
            return false;
        }
        $this->results_table = $results_table;
        // Before save(), so the row records it too (it stayed NULL otherwise).
        $this->valid = true;
        $this->save();

        return true;
    }

    /**
     * The settings a new survey may take from its import — a JSON file's
     * "settings", or a spreadsheet's `settings` sheet (the sheet the xlsx
     * export writes) — allowlisted and validated, so every import path
     * accepts exactly the same keys and values. Engine settings included:
     * a v2 study exported and uploaded again comes back on v2.
     *
     * @param array $raw name => value
     * @return array
     */
    public static function importableSettings(array $raw)
    {
        $allowed_settings = [
            'maximum_number_displayed', 'displayed_percentage_maximum',
            'add_percentage_points', 'expire_after',
            'expire_invitation_after', 'expire_invitation_grace',
            'enable_instant_validation',
            'unlinked', 'hide_results', 'use_paging',
            'google_file_id',
            // v2 engine settings — exported by getSettings(), so a
            // bundle round-trips its rendering mode. Validated below
            // exactly as the admin settings form does
            // (AdminSurveyController::settingsAction); an invalid value
            // is dropped rather than coerced, so a bad bundle imports
            // with the column default.
            'rendering_mode', 'offline_mode', 'allow_previous',
            'layout', 'language', 'next_button_label', 'submit_button_label',
        ];
        $filtered = array_intersect_key($raw, array_flip($allowed_settings));
        $filtered = self::validateEngineSettings($filtered);
        // The integer columns: a spreadsheet `settings` sheet carries an
        // exported NULL (or a FALSE cell) as '', and '' or any other
        // non-integer is an error for an INT column under STRICT_ALL_TABLES —
        // so re-uploading an exported xlsx failed. Such a value is dropped
        // (the column default applies), like an invalid engine setting; so
        // is one outside what the settings form allows (or, without a limit
        // there, what the unsigned column holds): STRICT refuses -1 and 300 %
        // too, after the survey has been created.
        $ranges = array(
            'maximum_number_displayed' => array(0, 3000),
            'displayed_percentage_maximum' => array(1, 100),
            'add_percentage_points' => array(0, 100),
            'expire_after' => array(0, 3153600),
            'expire_invitation_after' => array(0, 4294967295),
            'expire_invitation_grace' => array(0, 4294967295),
            'enable_instant_validation' => array(0, 1),
            'unlinked' => array(0, 1),
            'hide_results' => array(0, 1),
            'use_paging' => array(0, 1),
        );
        foreach ($ranges as $key => $range) {
            if (!array_key_exists($key, $filtered)) {
                continue;
            }
            $v = $filtered[$key];
            if ($v === null) {
                // A JSON null is an exported NULL: kept where the column
                // allows it (these two are NOT NULL).
                if (in_array($key, array('hide_results', 'use_paging'), true)) {
                    unset($filtered[$key]);
                }
            } elseif (is_bool($v)) {
                $filtered[$key] = (int) $v;
            } elseif ((is_int($v) || (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v)))
                    && (int) $v >= $range[0] && (int) $v <= $range[1]) {
                $filtered[$key] = (int) $v;
            } else {
                unset($filtered[$key]);
            }
        }
        // Validate google_file_id at the WRITE boundary, not only on the
        // download path (google_get_sheet_id). This value is
        // attacker-controlled on the import path — a shared run-bundle's
        // settings.google_file_id — and admin templates render it
        // unescaped inside an href via google_get_sheet_link() (raw string
        // interpolation). Storing a crafted value would be stored XSS on
        // the admin origin, the exact boundary the study-subdomain split
        // exists to protect. Drop anything that isn't a real Drive file id
        // (base64url) so a bad bundle imports without the link rather than
        // with a payload. The templates are also being escaped (defence in
        // depth), but the value should never reach the DB malformed.
        if (isset($filtered['google_file_id']) && !google_is_valid_sheet_id($filtered['google_file_id'])) {
            unset($filtered['google_file_id']);
        }
        return $filtered;
    }

    /**
     * The runs that use this study as a classic Survey unit (the study's
     * survey_units row is type 'Survey' while it is on v1, and every
     * placement shares that row). While there are any, "Upgrade to form_v2"
     * refuses the study: it would switch the engine under those runs'
     * participants. To try v2, the author copies the survey.
     *
     * @return list<string> run names
     */
    public function runsUsingAsSurvey()
    {
        $rows = $this->db->execute(
            'SELECT DISTINCT r.name FROM `survey_run_units` ru
             JOIN `survey_units` u ON u.id = ru.unit_id AND u.type = \'Survey\'
             JOIN `survey_runs` r ON r.id = ru.run_id
             WHERE ru.unit_id = :sid ORDER BY r.name',
            array('sid' => (int) $this->id)
        );
        return array_map(function ($row) { return (string) $row['name']; }, (array) $rows);
    }

    /**
     * Does a study read its answers from the long format
     * (survey_items_display) — for R, exports, the results page, result
     * counts and backups? A v2 study always; a v1 study only where the
     * operator opts in (form_v2_long_reads, off by default), so a v1 study
     * reads its own results table exactly as before.
     */
    public static function readsLong($renderingMode)
    {
        return $renderingMode === 'v2' || (bool) Config::get('form_v2_long_reads', false);
    }

    /**
     * A new survey arrives on form_v2 only where the operator enabled form_v2
     * authoring. The one rule for every path that creates a survey from a
     * file — the admin upload, the API, a run-bundle import (a Form unit in a
     * bundle included: Run::importUnits asks for v2 through its settings, and
     * this gate decides).
     */
    public static function engineGate(array $settings)
    {
        if (($settings['rendering_mode'] ?? null) === 'v2' && !Config::get('form_v2_enabled')) {
            $settings['rendering_mode'] = 'v1';
        }
        return $settings;
    }

    /**
     * Store the importable part of $raw (importableSettings) on this new
     * survey and say what was taken.
     */
    protected function applyImportedSettings(array $raw)
    {
        $settings = self::engineGate(self::importableSettings($raw));
        // A survey created from a Google Sheet is linked to that sheet
        // (createFromFile); the `settings` tab an export wrote names the
        // sheet of the survey it was exported from, which must not replace it.
        if (!empty($this->google_file_id)) {
            unset($settings['google_file_id']);
        }
        if (!$settings) {
            return;
        }
        $this->assignProperties($settings);
        $this->save();
        $shown = array();
        foreach ($settings as $k => $v) {
            $shown[] = h($k) . ' = ' . h((string) $v);
        }
        $this->messages[] = 'Settings taken from the upload: ' . implode(', ', $shown) . '.';
    }

    /**
     * Materialise a survey from an exported `survey_data` object (a run
     * bundle's per-unit payload, or the standalone survey JSON upload) and
     * load its items.
     *
     * A study of that name owned by the current user is reused (items
     * replaced); otherwise it is created. With $options['run'] set, a
     * Survey unit for the study is also placed in that run — the
     * behaviour Survey::create relies on. Pass $options['place_unit'] =
     * false to get the study only, with no unit placed in any run.
     *
     * @param object $data  {name, items, settings?}
     * @param array $options
     * @return SurveyStudy|false the study, or false on failure
     */
    public static function createFromData($data, $options = [])
    {
        unset($options['survey_data']);
        $place_unit = !array_key_exists('place_unit', $options) || $options['place_unit'];
        unset($options['place_unit']);
        if (empty($data->name) || empty($data->items)) {
            return false;
        }

        // Survey ownership is bound to the caller. Honor an explicit
        // $options['user_id'] only when it agrees with the current user.
        // createFromFile applies the same check on the insert path.
        $current_user = Site::getCurrentUser();
        if (isset($options['user_id']) && (int) $options['user_id'] !== (int) $current_user->id) {
            return false;
        }
        $user = $current_user;

        $study = self::loadByUserAndName($user, $data->name);

        if ($study->valid) {
            // Survey exists so use existing data
            if ($place_unit && !empty($options['run'])) {
                $unit = [
                    'id' => $study->id,
                    'study_id' => $study->id,
                    'type' => 'Survey',
                    'importing' => true,
                ];
                $options['add_to_run'] = true;

                RunUnitFactory::make($options['run'], $unit)->create($options);
            }
        } else {
            $study = new SurveyStudy();
            $file = ['name' => $data->name . '.xlsx'];
            unset($options['is_import']);
            // createFromFile places the unit as a side effect of the run
            // options it is handed (RunUnit::create assigns `run`,
            // `position`, `type`, … and addToRun inserts the row). When the
            // caller places the study itself, hand it only what the
            // ownership check needs, or the study's survey_units row would
            // inherit the caller's unit type and a second unit would land
            // in the run.
            $file_options = $place_unit ? $options : ['user_id' => ($options['user_id'] ?? null)];
            if (!$study->createFromFile($file, $file_options)) {
                return false;
            }

            // Save settings. Allowlist what may be written before
            // assignProperties touches the model — Model::assignProperties
            // uses property_exists() and would otherwise let the caller
            // smuggle id, user_id, name, results_table, created, etc.
            // through this object. The follow-up save() resolves UPDATE vs
            // INSERT by entry_exists($table, ['id' => $this->id]), so a
            // smuggled id pointed UPDATE at any pre-existing row.
            // `google_file_id` is on the list (v1.7.1) although 987cfba8
            // deliberately left it off: getSettings() EXPORTS it, so
            // excluding it here silently unlinked every survey in an
            // imported run bundle from its Google Sheet, with no notice —
            // a round-trip that quietly loses data is worse than the
            // narrow risk of honouring it. That risk is not mass
            // assignment (the id/user_id/results_table class stays out);
            // it is that the value reaches google_download_survey_sheet(),
            // which interpolates it into a destination FILENAME. That is
            // now guarded at the shared choke point in
            // google_get_sheet_id() instead of by omission here, which
            // also covers the admin settings form — that path has allowed
            // google_file_id through all along (AdminSurveyController:515),
            // so omitting it here never actually closed the hazard.
            $raw_settings = isset($data->settings) ? (array) $data->settings : [];
            $filtered = self::engineGate(self::importableSettings($raw_settings));
            $data->settings = array_merge([
                'maximum_number_displayed' => 0,
                'displayed_percentage_maximum' => 100,
                'add_percentage_points' => 0,
            ], $filtered);
            $study->assignProperties($data->settings);
            $study->is_new = true;
            $study->save();
        }

        // Mock SpreadSheetReader to use existing mechanism of creating survey items
        $reader = new SpreadsheetReader();
        foreach (array_values((array) $data->items) as $position => $item) {
            if (!empty($item->choices) && !empty($item->choice_list)) {
                foreach ($item->choices as $name => $label) {
                    $reader->choices[] = array(
                        'list_name' => $item->choice_list,
                        'name' => $name,
                        'label' => $label,
                    );
                }
                unset($item->choices);
            }
            // Strip export-only / unrecognised fields (e.g. showif_js,
            // label_parsed is allowed, item_order is allowed) so a full JSON
            // survey export imports cleanly — addSurveyItem rejects unknown
            // columns.
            $row = array_intersect_key((array) $item, array_flip($reader->getAllowedColumns()));
            // Same default the spreadsheet reader applies per row: an item
            // without an explicit order sits where it appears in the file.
            // A formr JSON export carries item_order, but a hand-written
            // survey JSON usually doesn't — and with every item_order NULL
            // the render order (ORDER BY item_order) is whatever the engine
            // returns, so pages came out scrambled.
            if (!isset($row['order']) || !is_formr_truthy($row['order'])) {
                $row['order'] = $position + 1;   // 1-based, like the reader's row numbers
            }
            if (!isset($row['item_order']) || !is_formr_truthy($row['item_order'])) {
                $row['item_order'] = $row['order'];
            }
            $reader->addSurveyItem($row);
        }

        if ($study->saveUploadedItemsFromReader($reader)) {
            if (!empty($study->warnings)) {
                alert('<ul><li>' . implode("</li><li>", $study->warnings) . '</li></ul>', 'alert-warning');
            }

            if (!empty($study->messages)) {
                alert('<ul><li>' . implode("</li><li>", $study->messages) . '</li></ul>', 'alert-info');
            }

            return $study;
        } else {
            alert('<ul><li>' . implode("</li><li>", $study->errors) . '</li></ul>', 'alert-danger');
            return false;
        }
    }

    /**
     * Validate the v2 engine settings of an imported bundle the way the
     * admin settings form does. Keys that fail validation are removed so
     * the column default applies; keys that are absent stay absent.
     *
     * @param array $settings allowlisted settings from createFromData
     * @return array
     */
    private static function validateEngineSettings(array $settings)
    {
        if (array_key_exists('rendering_mode', $settings) && !in_array($settings['rendering_mode'], ['v1', 'v2'], true)) {
            unset($settings['rendering_mode']);
        }
        foreach (['offline_mode', 'allow_previous'] as $flag) {
            if (array_key_exists($flag, $settings)) {
                // Exports carry DB strings ("1"), hand-written JSON ints or bools.
                $settings[$flag] = (int) in_array($settings[$flag], [1, '1', true], true);
            }
        }
        if (array_key_exists('layout', $settings) && !in_array($settings['layout'], ['default', 'solo'], true)) {
            unset($settings['layout']);
        }
        if (array_key_exists('language', $settings)) {
            $lang = trim((string) $settings['language']);
            if (self::isLanguageTag($lang)) {
                $settings['language'] = $lang;
            } else {
                unset($settings['language']);
            }
        }
        foreach (['next_button_label', 'submit_button_label'] as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = self::normalizeButtonLabel($settings[$key]);
            }
        }
        return $settings;
    }

    /**
     * A study language (patch 114): a BCP 47-shaped tag. The one check for
     * the admin settings form and imports.
     */
    public static function isLanguageTag($tag)
    {
        return is_string($tag) && (bool) preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag);
    }

    /**
     * A custom navigation button label (#322): plain text, one line, at most
     * 100 characters; anything empty means "use the built-in wording" (NULL).
     * Markup is stripped rather than escaped because the label is shown as
     * text, and a button is no place for it.
     */
    public static function normalizeButtonLabel($label) {
        if (!is_scalar($label)) {
            return null;
        }
        $label = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $label)));
        if ($label === '') {
            return null;
        }
        return mb_substr($label, 0, 100);
    }

    /**
     * @param array $settingsOverride for a NEW survey: settings the uploader
     *   chose on the form (the engine), applied over the file's own
     *   `settings` sheet
     */
    public function uploadItems($file, $can_delete = false, $is_new = false, array $settingsOverride = array())
    {
        umask(0002);
        ini_set('memory_limit', Config::get('memory_limit.survey_upload_items'));

        $filepath = $file['tmp_name'];
        $filename = $file['name'];
        $this->can_delete = $can_delete;
        $this->is_new = $is_new;

        $this->messages[] = "Items sheet ({$filename}) uploaded to survey <b>{$this->name}</b>.";

        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($filepath);

        $this->errors = array_merge($this->errors, $reader->errors);
        $this->warnings = array_unique(array_merge($this->warnings, $reader->warnings));
        $this->messages = array_unique(array_merge($this->messages, $reader->messages));

        // if items are ok, make actual survey
        // A new survey takes its settings from the file (the `settings` sheet
        // an xlsx export writes, so an exported study round-trips — its
        // engine included) and from the upload form, before its items go in:
        // the engine decides which items are allowed (formV2Unsupported).
        // An existing survey's settings are never changed by an upload.
        if ($is_new && empty($this->errors)) {
            if (($reader->settings['rendering_mode'] ?? null) === 'v2'
                && (($settingsOverride['rendering_mode'] ?? 'v2') === 'v1' || !Config::get('form_v2_enabled'))) {
                $this->warnings[] = 'The file is set to v2, but the survey was created on v1'
                    . (Config::get('form_v2_enabled') ? ', as you chose.' : ', because v2 is not available on this formr server.');
            }
            $this->applyImportedSettings(array_merge($reader->settings, $settingsOverride));
        }

        if (empty($this->errors) && $this->saveUploadedItemsFromReader($reader)) {
            if (!empty($this->warnings)) {
                alert('<ul><li>' . implode("</li><li>", $this->warnings) . '</li></ul>', 'alert-warning');
            }

            if (!empty($this->messages)) {
                alert('<ul><li>' . implode("</li><li>", $this->messages) . '</li></ul>', 'alert-info');
            }

            // save original survey sheet
            $filename = 'formr-survey-' . Site::getCurrentUser()->id . '-' . $filename;
            $file = Config::get('survey_upload_dir') . '/' . $filename;

            if (file_exists($filepath) && (move_uploaded_file($filepath, $file) || rename($filepath, $file))) {
                $this->original_file = $filename;
                $this->modified = mysql_datetime();
                $this->save();
            } else {
                alert('Unable to save original uploaded file', 'alert-warning');
            }

            return true;
        } else {
            alert('<ul><li>' . implode("</li><li>", $this->errors) . '</li></ul>', 'alert-danger');
            return false;
        }
    }

    protected function resultsTableExists()
    {
        if (!$this->results_table) {
            return false;
        }

        return $this->db->table_exists($this->results_table);
    }

    /**
     * Per-request cache of a wide results table's column names, keyed by
     * table name. [] when the table does not exist. Static because the same
     * study is routinely instantiated several times per request (RunUnit,
     * UnitSession::getRunData, the API); one SHOW COLUMNS per table per
     * request is enough.
     *
     * @var array<string, string[]>
     */
    private static $wideColumnsCache = [];

    /**
     * Column names of a wide results table (F2/F3). The single probe every
     * wide-table writer/reader goes through: which item columns exist, and
     * whether the legacy table pre-dates the `iteration` column.
     *
     * @param string $resultsTable
     * @return string[] empty when the table is missing
     */
    public static function wideTableColumns($resultsTable)
    {
        $resultsTable = (string) $resultsTable;
        if ($resultsTable === '') {
            return [];
        }
        if (!array_key_exists($resultsTable, self::$wideColumnsCache)) {
            // getTableDefinition($t, 'Field') keys the SHOW COLUMNS rows by name.
            $cols = DB::getInstance()->getTableDefinition($resultsTable, 'Field');
            self::$wideColumnsCache[$resultsTable] = is_array($cols) ? array_map('strval', array_keys($cols)) : [];
        }
        return self::$wideColumnsCache[$resultsTable];
    }

    /** Drop the cached column list for a table after DDL on it. */
    public static function forgetWideTableColumns($resultsTable)
    {
        unset(self::$wideColumnsCache[(string) $resultsTable]);
    }

    /**
     * Drop every cached column list. The cache is meant to live for one
     * request; a long-running process (the queue daemons) calls this once
     * per pass, or it would keep a table's columns from before an upload
     * that dropped or added some.
     */
    public static function forgetAllWideTableColumns()
    {
        self::$wideColumnsCache = [];
    }

    /**
     * The results column of every item of a study, as Item::getResultField()
     * declares it — what the wide table holds and so what R sees:
     * name => [
     *   'live' => not soft-deleted,
     *   'column' => 'int' | 'float' | 'text', or null (no results column),
     *   'blank_is_null' => blankAnswerIsNull(),
     * ].
     * A live item wins over a soft-deleted namesake, the highest id among
     * equals. The one reading for the R overlay's allowlist
     * (RCallService::allowedOverlayNames) and the long read's casts
     * (UnitSession::getLongReadCasts). Memoized per process: item definitions
     * change only through an upload, a different request; long-running
     * processes (the queue daemons) call forgetColumnTypes() each pass.
     *
     * @param int $studyId
     * @return array<string, array{live: bool, column: string|null, blank_is_null: bool}>
     */
    public static function columnTypes($studyId)
    {
        $studyId = (int) $studyId;
        if (isset(self::$columnTypesCache[$studyId])) {
            return self::$columnTypesCache[$studyId];
        }
        $db = DB::getInstance();
        $rows = $db->select('id, study_id, type, choice_list, type_options, name, deleted')
            ->from('survey_items')
            ->where(array('study_id' => $studyId))
            ->order(DB::raw('`deleted` IS NULL'), 'DESC')
            ->order('id', 'DESC')
            ->fetchAll();
        $types = array();
        if ($rows) {
            // Choice lists decide the column type for mc/check families
            // (numeric choice names → TINYINT, string names → VARCHAR), as on
            // the upload path.
            $choiceLists = array();
            foreach ((array) $db->select('list_name, name, label')->from('survey_item_choices')
                    ->where(array('study_id' => $studyId))->order('id', 'ASC')->fetchAll() as $c) {
                $choiceLists[$c['list_name']][$c['name']] = $c['label'];
            }
            $factory = new ItemFactory($choiceLists);
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                if (isset($types[$name])) {
                    continue;
                }
                $item = $factory->make($row);
                $field = ($item === false || !$item->isStoredInResultsTable()) ? null : $item->getResultField();
                $types[$name] = array(
                    'live' => empty($row['deleted']),
                    'column' => self::columnKind($name, $field),
                    'blank_is_null' => $item !== false && self::blankAnswerIsNull($item),
                    // The participant gives its value (not the server: a
                    // random draw, the IP, a URL parameter, …).
                    'input' => $item !== false && $item->requiresUserInput(),
                );
            }
        }
        return self::$columnTypesCache[$studyId] = $types;
    }

    public static function forgetColumnTypes()
    {
        self::$columnTypesCache = array();
    }

    /** @var array<int, array> columnTypes() memo */
    private static $columnTypesCache = array();

    /**
     * The kind of column a field definition declares: 'int' for the integer
     * families, 'float' for FLOAT/DOUBLE, 'text' for everything else
     * (VARCHAR/TEXT/DATE…, and DECIMAL, which PDO hands back as a string),
     * null for no column.
     *
     * @param string $name item name, as prefixed onto the field definition
     * @param string|null $field e.g. "`age` INT UNSIGNED DEFAULT NULL"
     * @return string|null
     */
    public static function columnKind($name, $field)
    {
        if (!is_string($field)) {
            return null;
        }
        $type = strtoupper(ltrim(substr($field, strlen("`{$name}` "))));
        if (preg_match('/^(TINY|SMALL|MEDIUM|BIG)?INT\b/', $type)) {
            return 'int';
        }
        if (preg_match('/^(FLOAT|DOUBLE)\b/', $type)) {
            return 'float';
        }
        return 'text';
    }

    /**
     * Does a blank answer ('') to $item read as NULL in the wide table?
     *
     * survey_items_display.answer stores getReply() of the raw posted value;
     * the wide column stores getReply() of the validated one, and validation
     * turns a single-valued item's blank reply into NULL. So a long read must
     * map '' back to NULL for these items, or R sees "" where it saw NA (and a
     * numeric column turns into character). Multi-valued items (mc_multiple,
     * select_multiple, …) stored '' in the wide table too, and items that take
     * no input are saved unvalidated, so both keep their ''.
     */
    public static function blankAnswerIsNull(Item $item)
    {
        return $item->requiresUserInput() && !$item->acceptsArrayReply();
    }

    /**
     * Whether this study's wide table carries the `iteration` column (F2).
     * Wide tables created before commit 89383543 (2024-07) lack it; writers
     * must then omit `iteration` and readers must take it from
     * survey_unit_sessions.study_iteration instead. False when no wide table
     * exists (long-only mode).
     *
     * @return bool
     */
    public function resultsTableHasIteration()
    {
        if (!$this->results_table) {
            return false;
        }
        return in_array('iteration', self::wideTableColumns($this->results_table), true);
    }

    /**
     * The highest iteration the results table has handed out: its largest
     * `iteration`, or what its AUTO_INCREMENT went past, whichever is
     * higher. Rows deleted from the top (a deleted participant) leave the
     * second above the first, and v1.11.0, which numbered by AUTO_INCREMENT,
     * never gave their numbers out again. 0 without the column.
     *
     * @return int
     */
    public function resultsTableHighWater()
    {
        if (!$this->resultsTableHasIteration()) {
            return 0;
        }
        $table = str_replace('`', '``', $this->results_table);
        return (int) $this->db->execute(
            "SELECT GREATEST((SELECT COALESCE(MAX(`iteration`), 0) FROM `{$table}`),
                (SELECT COALESCE(MAX(`AUTO_INCREMENT`), 1) - 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t))",
            array('t' => (string) $this->results_table), true
        );
    }

    protected function toArray()
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'results_table' => $this->results_table,
            'valid' => $this->valid,
            'maximum_number_displayed' => $this->maximum_number_displayed,
            'displayed_percentage_maximum' => $this->displayed_percentage_maximum,
            'add_percentage_points' => $this->add_percentage_points,
            'expire_after' => $this->expire_after,
            'expire_invitation_after' => $this->expire_invitation_after,
            'expire_invitation_grace' => $this->expire_invitation_grace,
            'enable_instant_validation' => $this->enable_instant_validation,
            'original_file' => $this->original_file,
            'google_file_id' => $this->google_file_id,
            'unlinked' => $this->unlinked,
            'hide_results' => $this->hide_results,
            'use_paging' => $this->use_paging,
            'rendering_mode' => $this->rendering_mode,
            'offline_mode' => $this->offline_mode,
            'allow_previous' => $this->allow_previous,
            'layout' => $this->layout,
            'language' => $this->language,
            'next_button_label' => $this->next_button_label,
            'submit_button_label' => $this->submit_button_label,
            'created' => $this->created,
            'modified' => $this->modified,
        ];
    }

    /**
     * Get All choice lists in this survey with associated items
     *
     * @param array $specific An array if list_names which if defined, only lists specified in the array will be returned
     * @param string $label
     * @return $array Returns an array indexed by list name;
     */
    public function getChoices($specific = null, $label = 'label')
    {
        $select = $this->db->select('list_name, name, label, label_parsed');
        $select->from('survey_item_choices');
        $select->where(array('study_id' => $this->id));

        if (!$specific && $specific !== null) {
            return array();
        } elseif ($specific !== null) {
            $select->whereIn('list_name', $specific);
        }
        $select->order('id', 'ASC');

        $lists = array();
        $stmt = $select->statement();

        // If we are not hunting for a particular field name return list as is
        if (!$label) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($lists[$row['list_name']])) {
                $lists[$row['list_name']] = array();
            }
            $lists[$row['list_name']][$row['name']] = $row[$label];
        }

        return $lists;
    }

    public function getChoicesForSheet()
    {
        return $this->db->select('list_name, name, label')
            ->from('survey_item_choices')
            ->where(array('study_id' => $this->id))
            ->order('id', 'ASC')->fetchAll();
    }

    /**
     * Create a new survey using items uploaded by the spreadsheet reader
     * Cases:
     * 0. new creation -> do it
     * 1. is the upload identical to the existing -> do nothing
     * 2. are there any results at all? -> nuke existing shit, do fresh start
     * 3. does the upload entail text/minor changes to the item table -> simply do changes
     * 4. does the upload entail changes to results (i.e. will there be deletion) -> confirm deletion
     *    4a. deletion confirmed: backup results, modify results table, drop stuff
     *    4b. deletion not confirmed: cancel the whole shit.
     * 
     * @param SpreadsheetReader|JsonReader $reader
     * @return boolean
     */
    protected function saveUploadedItemsFromReader($reader)
    {

        // Get old choice lists for getting old items
        $choiceLists = $this->getChoices();
        $itemFactory = new ItemFactory($choiceLists);

        // Get old items, mark them as false meaning all are vulnerable for delete.
        // When the loop over survey items ends you will know which should be deleted.
        $old_items = array();
        $old_items_in_results = array();
        $old_showifs = array(); // name => showif before the upload, see invalidateShowifVerdicts()

        foreach ($this->getItems() as $item) {
            $old_showifs[$item['name']] = $item['showif'];
            if (($object = $itemFactory->make($item)) !== false) {
                $old_items[$item['name']] = $object->getResultField();
                if ($object->isStoredInResultsTable()) {
                    $old_items_in_results[] = $item['name'];
                }
            }
        }

        try {

            $this->db->beginTransaction();
            $data = $this->addItems($reader);
            $new_items = $data['new_items'];
            $result_columns = $data['result_columns'];

            $added = array_diff_assoc($new_items, $old_items);
            $deleted = array_diff_assoc($old_items, $new_items);
            $unused = $itemFactory->unusedChoiceLists();

            if ($unused) {
                $this->warnings[] = __("These choice lists were not used: '%s'", implode("', '", $unused));
            }

            // If there are items to delete, check if user confirmed deletion and if so check if back up succeeded
            if (count($deleted) > 0) {
                if ($this->hasRealData() && !$this->can_delete) {
                    $deleted_columns_string = implode(", ", array_keys($deleted));
                    $this->errors[] = "<strong>No permission to delete data</strong>. Enter the survey name, if you are okay with data being deleted from the following items: " . $deleted_columns_string;
                }
                if ($this->hasRealData() && $this->can_delete && !$this->backupResults($old_items_in_results)) {
                    $this->errors[] = "<strong>Back up failed.</strong> Deletions would have been necessary, but backing up the item table failed, so no modification was carried out.</strong>";
                }
            }

            // If there are errors at this point then terminate to rollback all changes inluding adding choices and inserting items
            if (!empty($this->errors)) {
                throw new Exception('Process terminated prematurely due to errors');
            }

            $actually_deleted = array_diff(array_keys($deleted), array_keys($added));
            if ($deleted && $actually_deleted) {
                // (items merely re-typed appear in both $deleted and $added by
                // name, so they're excluded here — they only need the wide-table
                // column rebuild in alterResultsTable below.)
                //
                // Soft-delete (patch 112): flip removed / renamed-away items to a
                // `deleted` marker instead of DELETE. Keeping the survey_items row
                // means its CASCADE-linked survey_items_display answers survive, so
                // the long-format participant data is preserved and stays in
                // exports (marked deleted). Re-adding the same name later revives
                // the row (addItems clears the marker), restoring its old answers.
                $toDelete = implode(',', array_map(array($this->db, 'quote'), $actually_deleted));
                $studyId = (int) $this->id;
                $softDelQ = "UPDATE survey_items SET `deleted` = NOW() WHERE `name` IN ($toDelete) AND study_id = $studyId AND `deleted` IS NULL";
                $this->db->query($softDelQ);
            }

            // we start fresh if it's a new creation, no results table exist or it is completely empty
            if ($this->is_new || !$this->resultsTableExists() || !$this->hasData()) {
                if ($this->is_new && $this->resultsTableExists()) {
                    throw new Exception("Results table name conflict. This shouldn't happen. Please alert the formr admins");
                }
                // step 2
                if (!$this->is_new) {
                    $this->messages[] = "The results table was created anew, because it held no results or test sessions yet.";
                }
                // if there is no results table or no existing data at all, drop table, create anew
                // i.e. where possible prefer nuclear option
                $new_syntax = $this->getResultsTableSyntax($result_columns);
                if (!$this->createResultsTable($new_syntax)) {
                    throw new Exception('Unable to create a data table for survey results');
                }
            } else {
                // this will never happen if deletion was not confirmed, because this would raise an error
                // 2 and 4a
                $merge = $this->alterResultsTable($added, $deleted);
                if (!$merge) {
                    throw new Exception('Required modifications could not be made to the survey results table');
                }
            }

            $this->invalidateShowifVerdicts($old_showifs);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->errors[] = 'Error: ' . $e->getMessage();
            formr_log_exception($e, __CLASS__, $this->errors);
            return false;
        }
    }

    /**
     * Forget the showif verdicts that unfinished sessions cached for items
     * whose showif just changed.
     *
     * survey_items_display.hidden is a per-session cache of the server-side
     * showif verdict: SpreadsheetRenderer writes it the first time an item
     * comes up for rendering (1 = hidden, gone for good; 0 = shown; NULL =
     * undecided / left to the page's JS) and never asks R again —
     * Item::getShowIf() returns false once `hidden` is set, and the item
     * query skips hidden = 1 rows outright. Re-uploading the item table
     * keeps item ids (INSERT … ON DUPLICATE KEY UPDATE) and never touched
     * these rows, so every session that predated the upload — a participant
     * mid-survey, or the admin's own in-flight "Test Survey" — kept routing
     * by the OLD showif for as long as it lived. The admin saw the new code
     * in the item table, watched the item stay hidden, and the only thing
     * that "fixed" it was deleting and recreating the survey (new ids, new
     * sessions). Resetting the verdict to NULL makes the next render
     * evaluate the new showif; item views already saved are untouched.
     *
     * @param array $old_showifs name => showif as stored before the upload
     * @return int number of item views reset
     */
    protected function invalidateShowifVerdicts(array $old_showifs)
    {
        $changed = array();
        foreach ($this->getItems('id, name, showif') as $item) {
            $old = array_key_exists($item['name'], $old_showifs) ? $old_showifs[$item['name']] : null;
            if (self::showifChanged($old, $item['showif'])) {
                $changed[(int) $item['id']] = $item['name'];
            }
        }
        if (!$changed) {
            return 0;
        }

        $ids = implode(',', array_keys($changed)); // ints, from the DB
        $reset = (int) $this->db->exec(
            "UPDATE `survey_items_display` d
               JOIN `survey_unit_sessions` s ON s.id = d.session_id
              SET d.`hidden` = NULL
              WHERE d.item_id IN ($ids) AND d.`hidden` IS NOT NULL AND d.`saved` IS NULL
                AND s.unit_id = :study_id AND s.ended IS NULL AND s.expired IS NULL",
            array('study_id' => (int) $this->id)
        );
        if ($reset > 0) {
            $names = implode(', ', array_map(function ($name) {
                return '<code>' . h($name) . '</code>';
            }, $changed));
            $this->messages[] = "The showif of {$names} changed: {$reset} pending item view(s) in unfinished sessions (including a test you may have open) will re-evaluate it on their next page.";
        }
        return $reset;
    }

    /**
     * Did an item's showif change between two uploads? NULL, '' and
     * whitespace-only all mean "no showif". Public+static so it is unit
     * testable without a database.
     */
    public static function showifChanged($old, $new)
    {
        return trim((string) $old) !== trim((string) $new);
    }

    /**
     * Prepares the statement to insert new items and returns an associative containing
     * the SQL definition of the new items and the new result columns
     *
     * @param SpreadsheetReader|JsonReader $reader
     * @return array array(new_items, result_columns)
     */
    protected function addItems($reader)
    {
        // Save new choices and re-build the item factory
        $this->addChoices($reader);
        $choice_lists = $this->getChoices();
        $itemFactory = new ItemFactory($choice_lists);

        $definedColumns = [
            'name',
            'label',
            'label_parsed',
            'type',
            'type_options',
            'choice_list',
            'optional',
            'class',
            'showif',
            'value',
            'block_order',
            'item_order',
            'order',
            // study_id is not among the user_defined columns
        ];

        // Prepare SQL statement for adding items.
        $UPDATES = implode(', ', get_duplicate_update_string($definedColumns));
        // Re-uploading a name that was soft-deleted (patch 112) revives it: clear
        // the marker on upsert so an item that reappears in the sheet is live
        // again, and its preserved survey_items_display answers re-enter the form.
        $UPDATES .= ', `deleted` = NULL';
        $addStmt = $this->db->prepare(
            "INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `block_order`,`item_order`, `order`)
			VALUES (:study_id, :name, :label, :label_parsed, :type, :type_options, :choice_list, :optional, :class, :showif, :value, :block_order, :item_order, :order)
			ON DUPLICATE KEY UPDATE $UPDATES"
        );
        $addStmt->bindParam(":study_id", $this->id);

        $ret = array(
            'new_items' => array(),
            'result_columns' => array(),
        );

        // The form_v2 showif helper names (Item::FORM_COMPONENT_NAMES) are reserved
        // on v2 only: a v1 study never runs those helpers, and a copy of an
        // older v1 survey must upload (Item::allowLegacyHelperName). The
        // switch to v2 refuses a study that has one (formV2UnsupportedItems).

        foreach ($reader->survey as $row_number => $row) {
            $item = $itemFactory->make($row);
            if (!$item) {
                $this->errors[] = __("Row %s: Type %s is invalid.", $row_number, array_val($reader->survey[$row_number], 'type'));
                unset($reader->survey[$row_number]);
                continue;
            }
            if ($this->rendering_mode !== 'v2') {
                $item->allowLegacyHelperName();
            }

            if (!$this->collectItemValidation($item)) {
                unset($reader->survey[$row_number]);
                continue;
            }
            if ($this->rendering_mode === 'v2' && ($why = $item->formV2Unsupported())) {
                $this->errors[] = $why;
                unset($reader->survey[$row_number]);
                continue;
            }
            if ($this->rendering_mode !== 'v2' && ($why = $item->v1Unsupported())) {
                $this->errors[] = $why;
                unset($reader->survey[$row_number]);
                continue;
            }

            // if the parsed label is constant or exists
            if (!knitting_needed($item->label) && !$item->label_parsed) {
                $markdown = parsedown_text_safe($reader->parsedown, $item->label, "the label of item '{$item->name}'");
                $item->label_parsed = $markdown;
                if (mb_substr_count($markdown, "</p>") === 1 and preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
                    $item->label_parsed = $matches[1];
                }
            }

            foreach ($definedColumns as $param) {
                $addStmt->bindValue(":$param", $item->$param);
            }

            $result_field = $item->getResultField();
            $ret['new_items'][$item->name] = $result_field;
            $ret['result_columns'][] = $result_field;

            $addStmt->execute();
        }

        return $ret;
    }

    public function getItemsWithChoices($columns = null, $whereIn = null)
    {
        // The wide table is only required while it is written: with
        // form_v2_dual_write_results off the answers go to
        // survey_items_display alone (as createSurveyStudyRecord allows),
        // and an empty item list would make every write store nothing.
        if ($this->resultsTableExists() || !Config::get('form_v2_dual_write_results', true)) {
            $raw_items = $this->getItems($columns, $whereIn);
            // Only the choice lists these items use: every autosave, page
            // submit and sync asks for one or two items, and a study's whole
            // choice table (a 3,000-entry occupation list) was read each time.
            $lists = array();
            foreach ((array) $raw_items as $row) {
                if (!empty($row['choice_list'])) {
                    $lists[(string) $row['choice_list']] = true;
                }
            }
            $itemFactory = new ItemFactory($lists ? $this->getChoices(array_keys($lists)) : array());

            $items = array();
            foreach ($raw_items as $row) {
                $item = $itemFactory->make($row);
                // A v2 study stores a multiple choice's "0" (Item::replyValues):
                // set here, where the items are made, so every writer — page
                // submit, autosave, sync — shapes the answer the same way.
                if ($item instanceof Item && $this->rendering_mode === 'v2') {
                    $item->formV2 = true;
                }
                $items[$item->name] = $item;
            }
            return $items;
        } else {
            return array();
        }
    }

    public function getItemsInResultsTable()
    {
        if (($existingColumns = $this->db->getTableDefinition($this->results_table, 'Field'))) {
            $existingColumns = array_keys($existingColumns);
        }

        $items = $this->getItems();
        $names = array();
        $itemFactory = new ItemFactory(array());
        foreach ($items as $item) {
            $item = $itemFactory->make($item);
            if ($item->isStoredInResultsTable() && in_array($item->name, $existingColumns)) {
                $names[] = $item->name;
            }
        }
        return $names;
    }

    /**
     * Fold one item's validate() result into $this->errors / $this->warnings.
     *
     * array_merge, not `+`: validate() returns list arrays, and the union
     * operator keeps only the FIRST value at each numeric key — so of ten
     * invalid items the upload used to report just one, and the author fixed
     * them one re-upload at a time.
     *
     * @return bool whether the item is usable (validated without errors)
     */
    private function collectItemValidation(Item $item)
    {
        $val_results = $item->validate();
        if (!empty($val_results['val_errors'])) {
            $this->errors = array_merge($this->errors, $val_results['val_errors']);
            return false;
        }
        if (!empty($val_results['val_warnings'])) {
            $this->warnings = array_merge($this->warnings, $val_results['val_warnings']);
        }
        return true;
    }

    private function addChoices($reader)
    {
        // delete cascades to item display ?? FIXME so maybe not a good idea to delete then
        $definedColumns = ['list_name', 'name', 'label', 'label_parsed'];
        $deleted = $this->db->delete('survey_item_choices', array('study_id' => $this->id));
        $addChoiceStmt = $this->db->prepare(
            'INSERT INTO `survey_item_choices` (study_id, list_name, name, label, label_parsed) 
			 VALUES (:study_id, :list_name, :name, :label, :label_parsed )'
        );
        $addChoiceStmt->bindParam(":study_id", $this->id);

        foreach ($reader->choices as $choice) {
            $choice['label_parsed'] = null;

            if (isset($choice['list_name']) && isset($choice['name']) && isset($choice['label'])) {
                if (!knitting_needed($choice['label']) && empty($choice['label_parsed'])) { // if the parsed label is constant
                    $markdown = parsedown_text_safe($reader->parsedown, $choice['label'], "the label of choice '{$choice['list_name']}/{$choice['name']}'");
                    $choice['label_parsed'] = $markdown;
                    if (mb_substr_count($markdown, "</p>") === 1 and preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
                        $choice['label_parsed'] = $matches[1];
                    }
                }

                foreach ($definedColumns as $param) {
                    $addChoiceStmt->bindValue(":$param", $choice[$param]);
                }
                $addChoiceStmt->execute();
            }
        }

        return true;
    }

    private function getResultsTableSyntax($columns)
    {
        $columns = array_filter($columns); // remove null, false, '' values (note, fork, submit, ...)

        if (empty($columns)) {
            $columns_string = ''; # create a results tabel with only the access times
        } else {
            $columns_string = implode(",\n", $columns) . ",";
        }

        $create = "
		CREATE TABLE `{$this->results_table}` (
		  `session_id` INT UNSIGNED NOT NULL ,
		  `study_id` INT UNSIGNED NOT NULL ,
          `iteration` INT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
		  `created` DATETIME NULL DEFAULT NULL ,
		  `modified` DATETIME NULL DEFAULT NULL ,
		  `ended` DATETIME NULL DEFAULT NULL ,
		  `expired` DATETIME NULL DEFAULT NULL ,
	
		  $columns_string

		  PRIMARY KEY (`session_id`) ,
		  INDEX `idx_survey_results_survey_studies` (`study_id` ASC) ,
		  INDEX `idx_ending` (`session_id` DESC, `study_id` ASC, `ended` ASC) ,
		  CONSTRAINT
		    FOREIGN KEY (`session_id` )
		    REFERENCES `survey_unit_sessions` (`id` )
		    ON DELETE CASCADE
		    ON UPDATE NO ACTION,
		  CONSTRAINT
		    FOREIGN KEY (`study_id` )
		    REFERENCES `survey_studies` (`id` )
		    ON DELETE NO ACTION
		    ON UPDATE NO ACTION)
		ENGINE = InnoDB";
        return $create;
    }

    private function createResultsTable($syntax)
    {
        // The column probe cache is keyed by table name; a (re)create changes
        // the answer for this name within the same request.
        self::forgetWideTableColumns($this->results_table);
        // 1. Attempt to drop the table if allowed
        if ($this->deleteResults()) {
            $drop = $this->db->query("DROP TABLE IF EXISTS `{$this->results_table}` ;");
            $drop->execute();
            $this->restartIterations();
        } else {
            // If we aren't allowed to delete results, usually we return false.
            // However, checking if the table exists and returning true would be safer here,
            // but let's stick to the original logic for the 'else' block to minimize side effects.
            return false;
        }

        // 2. Attempt to create the table, catching "Table already exists" errors
        try {
            $create_table = $this->db->query($syntax);
            if ($create_table) {
                return true;
            }
        } catch (\PDOException $e) {
            // Check for SQLSTATE 42S01 (Base table or view already exists)
            // or MySQL Error Code 1050 (Table already exists)
            if ($e->getCode() === '42S01' || $e->getCode() == 1050) {
                // The table exists, which is good! We can proceed.
                // This prevents the crash and allows the Run to link to this existing table.
                return true;
            }

            // If it's a different error, re-throw it so we know something else is wrong
            throw $e;
        }

        return false;
    }

    /**
     * The items that keep this study off the form_v2 engine
     * (Item::formV2Unsupported), as name => reason. Empty = can run on v2.
     *
     * @return array<string, string>
     */
    public function formV2UnsupportedItems()
    {
        // With the choice lists: some rules read an item's choices.
        $factory = new ItemFactory($this->getChoices());
        $out = array();
        foreach ((array) $this->getItems() as $row) {
            if (($why = self::formV2UnsupportedReason((string) $row['name'], $factory->make($row)))) {
                $out[(string) $row['name']] = $why;
            }
        }
        return $out;
    }

    /**
     * Why an item cannot be on v2, or null — the one rule for the switch
     * (formV2UnsupportedItems) and the compatibility scan
     * (FormV2CompatScanner). Its name: a v1 study may keep an item named like
     * a member of the v2 form (Item::allowLegacyHelperName), which on v2
     * would shadow it. Or its type (Item::formV2Unsupported).
     *
     * @param string $name
     * @param Item|false|null $item
     * @return string|null
     */
    public static function formV2UnsupportedReason($name, $item)
    {
        if (in_array((string) $name, Item::FORM_COMPONENT_NAMES, true)) {
            return "'{$name}' is a name the v2 form uses itself (a showif function, or init), so the item would break showifs that use it. Rename the item before moving the survey to v2.";
        }
        return ($item && ($why = $item->formV2Unsupported())) ? $why : null;
    }

    /**
     * The items that keep this study off the classic v1 engine
     * (Item::v1Unsupported: bot_check, rank), as name => reason — the rule
     * the upload into a v1 study applies, for the switch back to v1.
     *
     * @return array<string, string>
     */
    public function v1UnsupportedItems()
    {
        $factory = new ItemFactory($this->getChoices());
        $out = array();
        foreach ((array) $this->getItems() as $row) {
            $item = $factory->make($row);
            if ($item && ($why = $item->v1Unsupported())) {
                $out[(string) $item->name] = $why;
            }
        }
        return $out;
    }

    /**
     * The select_or_add items whose choice list is written in one cell with
     * semicolons (`red;green;blue`), as name => why: v2 splits such a cell at
     * ";", v1 at "," (SelectOrAddOne_Item::unpackChoices), so on v1 it would
     * not be split. The way back's counterpart of the comma lists the
     * compatibility scan flags on the way to v2 (pack_with_semicolons).
     *
     * @return array<string, string>
     */
    public function semicolonListItems()
    {
        $lists = $this->getChoices();
        $factory = new ItemFactory(array());
        $out = array();
        foreach ((array) $this->getItems() as $row) {
            $item = $factory->make($row);
            if (!($item instanceof SelectOrAddOne_Item)) {
                continue;
            }
            $choices = isset($lists[$row['choice_list']]) ? array_values((array) $lists[$row['choice_list']]) : array();
            if (count($choices) === 1 && strpos((string) $choices[0], ';') !== false) {
                $out[(string) $item->name] = "'{$item->name}': the cell \"{$choices[0]}\" lists its choices with semicolons; v1 separates them with commas, so it would be a single choice";
            }
        }
        return $out;
    }

    /**
     * Put this study on the form_v2 engine: the one way a study gets there
     * after it exists (the admin switch). Besides the
     * flag, the multiple-choice columns of an older results table are
     * widened — v2 stores a choice value "0" that v1 dropped
     * (Item::replyValues), and a column sized without it could not hold a
     * full selection.
     */
    public function adoptFormV2()
    {
        $this->db->update('survey_studies', array('rendering_mode' => 'v2'), array('id' => (int) $this->id));
        $this->rendering_mode = 'v2';
        $this->syncUnitType();
        $this->widenMultipleChoiceColumns();
    }

    public function save()
    {
        $saved = parent::save();
        $this->syncUnitType();
        return $saved;
    }

    /**
     * A study IS its unit: every placement's survey_run_units.unit_id is the
     * study id, and the RunUnit factory instantiates by that survey_units
     * row's type. So the type follows the engine — 'Form' on v2, 'Survey'
     * on v1 — and every placement switches with the study.
     */
    protected function syncUnitType()
    {
        if (!$this->id) {
            return;
        }
        $this->db->exec(
            "UPDATE `survey_units` SET `type` = :type WHERE `id` = :id AND `type` IN ('Survey', 'Form')",
            array('type' => $this->rendering_mode === 'v2' ? 'Form' : 'Survey', 'id' => (int) $this->id)
        );
    }

    /**
     * Widen each mc_multiple / select_multiple results column that is
     * narrower than its item's current sizing. Never narrows, never touches
     * another column.
     *
     * @return string[] the columns widened
     */
    public function widenMultipleChoiceColumns()
    {
        // Results tables are MySQL tables; the unit tests' SQLite has neither
        // SHOW TABLES nor information_schema, and no results tables to widen.
        if ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || empty($this->results_table) || !$this->db->table_exists($this->results_table)) {
            return array();
        }
        $current = array();
        foreach ((array) $this->db->execute(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            array('t' => $this->results_table)
        ) as $col) {
            $current[(string) $col['COLUMN_NAME']] = $col;
        }
        $widened = $modify = array();
        foreach ((array) $this->getItemsWithChoices() as $item) {
            if (!($item instanceof McMultiple_Item || $item instanceof SelectMultiple_Item)) {
                continue;
            }
            $name = (string) $item->name;
            if (!isset($current[$name]) || strtolower((string) $current[$name]['DATA_TYPE']) !== 'varchar'
                    || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)
                    || !preg_match('/VARCHAR\s*\((\d+)\)/i', (string) $item->getResultField(), $m)) {
                continue;
            }
            if ((int) $m[1] > (int) $current[$name]['CHARACTER_MAXIMUM_LENGTH']) {
                $modify[] = "MODIFY `{$name}` VARCHAR(" . (int) $m[1] . ") DEFAULT NULL";
                $widened[] = $name;
            }
        }
        // One ALTER: each copies the whole table and holds its lock.
        if ($modify) {
            $this->db->query("ALTER TABLE `{$this->results_table}` " . implode(', ', $modify));
        }
        return $widened;
    }

    public function getItems($columns = null, $whereIn = null, $includeDeleted = false)
    {
        if ($columns === null) {
            // `deleted` (patch 112) is the soft-delete marker.
            $columns = "id, study_id, type, choice_list, type_options, name, label, label_parsed, optional, class, showif, value, block_order,item_order, deleted";
        }

        $select = $this->db->select($columns);
        $select->from('survey_items');
        $select->where(array('study_id' => $this->id));
        // Soft-deleted items (patch 112) don't render and aren't editable, so
        // they're excluded by default. Results/export callers pass
        // $includeDeleted = true to keep the preserved data recoverable.
        if (!$includeDeleted) {
            $select->where('`deleted` IS NULL');
        }
        if ($whereIn) {
            $select->whereIn($whereIn['field'], $whereIn['values']);
        }
        $select->order("item_order");
        return $select->fetchAll();
    }

    public function getItemsForSheet()
    {
        $get_items = $this->db->select('type, type_options, choice_list, name, label, optional, class, showif, value, block_order, item_order')
            ->from('survey_items')
            ->where(array('study_id' => $this->id))
            // soft-deleted items (patch 112) stay out of the editable sheet, so a
            // download→re-upload round-trip doesn't silently revive them.
            ->where('`deleted` IS NULL')
            ->order("`survey_items`.order")
            ->statement();

        $results = array();
        while ($row = $get_items->fetch(PDO::FETCH_ASSOC)) {
            // The list goes in its own `choice_list` column, ahead of `type`:
            // appended to the type cell ("type options list") the reader took
            // it for an option — and for text-like types it never reads a
            // list from the type cell at all, so a pattern item lost its
            // message. `type` keeps the type and its options, as authored.
            $type = trim($row['type'] . ' ' . (string) $row['type_options']);
            $list = $row['choice_list'];
            unset($row['type'], $row['type_options'], $row['choice_list']);
            $results[] = array('choice_list' => $list, 'type' => $type) + $row;
        }

        return $results;
    }

    public function getResults($items = null, $filter = null, array $paginate = null, $runId = null, $rstmt = false)
    {
        // A v2 study (and a v1 study where form_v2_long_reads opts in) is
        // read from the long format: survey_items_display pivoted into the
        // wide shape at read time (readsLong). A v1 study otherwise reads
        // its own results table, as it always has.
        if (self::readsLong($this->rendering_mode)) {
            $stmt = $this->pivotedResultsStatement($items, $filter, $paginate, $runId);
            if ($rstmt === true) {
                return $stmt;
            }
            $results = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = $row;
            }
            return $results;
        }

        return $this->getResultsWide($items, $filter, $paginate, $runId, $rstmt);
    }

    /**
     * getResults() read straight from the per-study wide results table: the
     * legacy read path, and what backupResults() saves before the wide table
     * is truncated or altered. array() when the table does not exist.
     */
    private function getResultsWide($items = null, $filter = null, array $paginate = null, $runId = null, $rstmt = false)
    {
        if ($this->resultsTableExists()) {
            ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

            $results_table = $this->results_table;
            if (!$items) {
                $items = $this->getItemsInResultsTable();
            }

            $count = $this->getResultCount();
            $get_all = true;
            if ($this->unlinked && $count['real_users'] <= 10) {
                if ($count['real_users'] > 0) {
                    alert("<strong>You cannot see the real results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
                }
                $get_all = false;
            }
            if ($this->unlinked) {
                $columns = array_map(function ($item) use ($results_table) {
                    return "`{$results_table}`.`" . $item . "`";
                }, $items);
                // considered showing data for test sessions, but then researchers could set real users to "test" to identify them
                /* 				$columns = array(
                  "IF(survey_run_sessions.testing, survey_run_sessions.session, '') AS session",
                  "IF(survey_run_sessions.testing, `{$results_table}`.`created`, '') AS created",
                  "IF(survey_run_sessions.testing, `{$results_table}`.`modified`, '') AS modified",
                  "IF(survey_run_sessions.testing, `{$results_table}`.`ended`, '') AS ended",
                  );
                 */
            } else {
                $columns = array('survey_run_sessions.session', "`{$results_table}`.*");
            }

            $select = $this->db->select($columns)
                ->from($results_table)
                ->leftJoin('survey_unit_sessions', "{$results_table}.session_id = survey_unit_sessions.id")
                ->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');
            if (!$get_all) {
                $select->where('survey_run_sessions.testing = 1');
            }

            if ($runId !== null) {
                $select->where(array('survey_run_sessions.run_id' => (int) $runId));
            }

            if ($paginate && isset($paginate['offset'])) {
                $order = isset($paginate['order']) ? $paginate['order'] : 'asc';
                $order_by = isset($paginate['order_by']) ? $paginate['order_by'] : "{$results_table}.session_id";
                if ($this->unlinked) {
                    $order_by = DB::raw('RAND()');
                }
                $select->order($order_by, $order);
                $select->limit($paginate['limit'], $paginate['offset']);
            } else {
                if ($this->unlinked) {
                    $order_by = DB::raw('RAND()');
                }
                if (isset($order_by)) {
                    $select->order($order_by);
                }
            }


            if (!empty($filter['session'])) {
                $session = $filter['session'];
                if (strlen($session) == 64) {
                    $select->where('survey_run_sessions.session = :session_eq');
                    $select->bindParams(array('session_eq' => $session));
                } else {
                    $select->like('survey_run_sessions.session', $session, 'right');
                }
            }

            if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
                $res_where = Template::replace($res_filter['query'], array('table' => $results_table));
                $select->where($res_where);
            }

            $stmt = $select->statement();
            if ($rstmt === true) {
                return $stmt;
            }

            $results = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                unset($row['study_id']);
                $results[] = $row;
            }

            return $results;
        } else {
            return array();
        }
    }

    /**
     * Pivot survey_items_display rows into the per-study wide shape.
     *
     * Used for rendering_mode='v2' studies as a step toward retiring the
     * dual-write to the per-study results table. Output column shape
     * mirrors the wide read in getResults(): session, session_id,
     * iteration, created, modified, ended, expired, then one column per
     * scorable item.
     *
     * Sourcing per column:
     *   session, session_id, ended, expired -> survey_unit_sessions / survey_run_sessions
     *   created -> MIN(survey_items_display.created)
     *   modified -> MAX(survey_items_display.saved)
     *   iteration -> survey_unit_sessions.study_iteration (patch 108).
     *     Pre-patch sessions are backfilled from the wide table by
     *     bin/backfill_study_iteration.php. ROW_NUMBER() fallback is
     *     used only when the column is unexpectedly NULL (e.g. backfill
     *     hasn't run yet for a study currently being read) so the
     *     export still produces a sensible value — that value won't
     *     match historical wide iteration byte-for-byte.
     *   answer columns -> MAX(CASE WHEN item_id = X THEN answer END)
     *
     * All answer values come back as TEXT — type coercion is the
     * researcher's responsibility via the formr R package or downstream
     * tooling.
     */
    private function pivotedResultsStatement($items = null, $filter = null, array $paginate = null, $runId = null)
    {
        ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

        // Resolve scorable items via the same ItemFactory check
        // getItemsInResultsTable uses, so the column set matches the
        // wide table's by construction. Don't cross-reference the wide
        // table — that's the point of this path.
        $rows = $this->getItems();
        $itemFactory = new ItemFactory(array());
        $scorableById = array();
        $blankIsNull = array();
        foreach ($rows as $row) {
            $it = $itemFactory->make($row);
            if ($it && $it->isStoredInResultsTable()) {
                $scorableById[(int) $row['id']] = $it->name;
                if (self::blankAnswerIsNull($it)) {
                    $blankIsNull[(int) $row['id']] = true;
                }
            }
        }
        if ($items) {
            $allowed = array_flip($items);
            $scorableById = array_filter($scorableById, function ($name) use ($allowed) {
                return isset($allowed[$name]);
            });
        }

        // Unlinked guard (mirrors getResults)
        $count = $this->getResultCount();
        $get_all = true;
        if ($this->unlinked && $count['real_users'] <= 10) {
            if ($count['real_users'] > 0) {
                alert("<strong>You cannot see the real results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
            }
            $get_all = false;
        }

        // The canonical results filters (complete / incomplete, the only ones
        // getResultsFilter defines) are translated to long-format semantics
        // inside buildPivotSql. Anything else is unknown wide-column SQL we
        // can't translate; refuse loudly rather than return wrong rows.
        if (!empty($filter['results']) && !in_array($filter['results'], array('all', 'complete', 'incomplete'), true)) {
            alert('This saved results filter is not supported by the long-form results path.', 'alert-warning');
            $empty = $this->db->prepare('SELECT NULL WHERE 1 = 0');
            $empty->execute();
            return $empty;
        }

        list($sql, $bindings) = self::buildPivotSql(
            $this->id,
            $scorableById,
            $this->unlinked,
            $get_all,
            $filter,
            $paginate,
            $runId,
            $blankIsNull
        );

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * The results filter's session condition on `rs`.`session`, for the long
     * reads (buildPivotSql, getResultCountLong): a full 64-character code
     * matches exactly, anything shorter as a prefix — with LIKE's wildcards
     * in it taken literally, as DB_Select::like() does on the wide path.
     *
     * @param string $session
     * @param array $bindings named placeholders, added to
     * @return string the SQL condition
     */
    private static function longSessionFilter($session, array &$bindings)
    {
        $session = (string) $session;
        if (strlen($session) == 64) {
            $bindings[':session_eq'] = $session;
            return '`rs`.`session` = :session_eq';
        }
        $bindings[':session_like'] = addcslashes($session, '%_\\') . '%';
        return '`rs`.`session` LIKE :session_like';
    }

    /**
     * Build the pivot SQL and named-placeholder bindings.
     *
     * Pure function: no DB access, no $this. Extracted so the SQL shape
     * can be unit-tested without standing up a MariaDB fixture.
     *
     * @param int    $studyId           SurveyStudy::id
     * @param array  $scorableById      [item_id => item_name] for scorable items
     * @param bool   $unlinked          $study->unlinked
     * @param bool   $getAll            False when unlinked guard restricts to test
     *                                   sessions only
     * @param array|null $filter        ['session' => ..., 'results' => ...]
     * @param array|null $paginate      ['offset', 'limit', 'order', 'order_by']
     * @param int|null $runId
     * @param array $blankIsNullIds  [item_id => true] for items whose blank
     *                                answer ('') the wide table stored as NULL
     *                                (see blankAnswerIsNull)
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function buildPivotSql(
        $studyId,
        array $scorableById,
        $unlinked,
        $getAll,
        $filter,
        array $paginate = null,
        $runId = null,
        array $blankIsNullIds = array()
    ) {
        $pivotCols = array();
        foreach ($scorableById as $id => $name) {
            // Item names are validated as identifiers at upload time
            // (createResultsTable would have failed otherwise). Backtick
            // any stray backticks defensively.
            $safeName = str_replace('`', '``', $name);
            $answer = UnitSession::longAnswerSql(sprintf('`sid`.`item_id` = %d', (int) $id), isset($blankIsNullIds[$id]));
            $pivotCols[] = sprintf("%s AS `%s`", $answer, $safeName);
        }

        // The column expressions are the R read's too (UnitSession::
        // longColumnSql). The iteration's ROW_NUMBER() fallback, for sessions
        // whose study_iteration backfill hasn't run, is a 1..N sequence per
        // result set, not byte-equal to a historical wide.iteration.
        $iterationExpr = UnitSession::longColumnSql('iteration');

        // `ended` (F4): survey_unit_sessions.ended is stamped when an OPERATOR
        // force-moves someone out of a survey too, while the wide column it
        // replaces deliberately stayed NULL there (the v1.8.0 "a force-move is
        // not a completion" rule). completedEndedSql() folds that rule back in
        // from `result`, so the exported `ended`, the complete/incomplete
        // filter and R's !is.na(survey$ended) keep meaning what they did in v1.
        $endedExpr = UnitSession::completedEndedSql('us');

        if ($unlinked) {
            // A study with no scorable items — notes only, or every answer item
            // soft-deleted (patch 112) — would otherwise build `SELECT  FROM`
            // and die with a 1064 on the Results page. One dummy column keeps
            // the statement valid and still reports the row count, which is all
            // an unlinked view of such a study can say.
            $selectParts = $pivotCols ? $pivotCols : array('NULL AS `formr_dummy`');
        } else {
            $selectParts = array_merge(array(
                "`rs`.`session` AS `session`",
                "`us`.`id` AS `session_id`",
                "$iterationExpr AS `iteration`",
                UnitSession::longColumnSql('created') . " AS `created`",
                UnitSession::longColumnSql('modified') . " AS `modified`",
                "$endedExpr AS `ended`",
                "`us`.`expired` AS `expired`",
            ), $pivotCols);
        }

        $select = implode(",\n            ", $selectParts);
        $bindings = array(':study_id' => (int) $studyId);
        $wheres = array('`i`.`study_id` = :study_id');

        $from = "FROM `survey_items_display` `sid`
        INNER JOIN `survey_items` `i` ON `i`.`id` = `sid`.`item_id`
        INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `sid`.`session_id`
        LEFT JOIN `survey_run_sessions` `rs` ON `rs`.`id` = `us`.`run_session_id`";
        $sql = "SELECT $select
        $from";

        if (!$getAll) {
            $wheres[] = '`rs`.`testing` = 1';
        }
        if ($runId !== null) {
            $wheres[] = '`rs`.`run_id` = :run_id';
            $bindings[':run_id'] = (int) $runId;
        }
        if (!empty($filter['session'])) {
            $wheres[] = self::longSessionFilter($filter['session'], $bindings);
        }

        // Canonical results filters, translated from the wide-column SQL in
        // getResultsFilter to long-format semantics:
        //   complete   — wide `ended IS NOT NULL`      → us.ended (plain WHERE)
        //   incomplete — wide `(created <> modified OR modified IS NULL) AND
        //                ended IS NULL`; modified is the last answer write, so
        //                the long equivalent needs aggregates (MAX(saved)) and
        //                lands in HAVING.
        $having = '';
        if (!empty($filter['results']) && $filter['results'] === 'complete') {
            $wheres[] = $endedExpr . ' IS NOT NULL';
        } elseif (!empty($filter['results']) && $filter['results'] === 'incomplete') {
            $having = "\n        HAVING (MIN(`sid`.`created`) <> MAX(`sid`.`saved`) OR MAX(`sid`.`saved`) IS NULL) AND {$endedExpr} IS NULL";
        }

        $whereSql = "\n        WHERE " . implode(' AND ', $wheres);
        $sql .= $whereSql;
        // GROUP BY the wide-row identity (one row per unit-session).
        // study_iteration is functionally dependent on us.id (PK), but
        // ONLY_FULL_GROUP_BY can't always prove that across the LEFT
        // JOIN, so list it explicitly.
        // `us`.`result` joins the list because $endedExpr reads it.
        $groupCols = array('`us`.`id`', '`us`.`study_iteration`', '`rs`.`session`', '`us`.`ended`', '`us`.`result`', '`us`.`expired`');
        $sql .= "\n        GROUP BY " . implode(', ', $groupCols);
        $sql .= $having;

        if ($unlinked) {
            $sql .= "\n        ORDER BY RAND()";
        } elseif ($paginate && isset($paginate['offset'])) {
            $order = isset($paginate['order']) && strtolower($paginate['order']) === 'desc' ? 'DESC' : 'ASC';
            $allowedOrderCols = array(
                'session_id' => '`us`.`id`',
                'iteration' => '`us`.`id`',
                'created' => 'MIN(`sid`.`created`)',
                'modified' => 'MAX(`sid`.`saved`)',
                'ended' => $endedExpr,
                'session' => '`rs`.`session`',
            );
            $orderByKey = isset($paginate['order_by']) ? $paginate['order_by'] : 'session_id';
            if (strpos($orderByKey, '.') !== false) {
                $orderByKey = substr($orderByKey, strrpos($orderByKey, '.') + 1);
            }
            $orderExpr = isset($allowedOrderCols[$orderByKey]) ? $allowedOrderCols[$orderByKey] : '`us`.`id`';
            $orderSql = sprintf("\n        ORDER BY %s %s", $orderExpr, $order);
            $limitSql = sprintf("\n        LIMIT %d OFFSET %d", (int) $paginate['limit'], (int) $paginate['offset']);
            // A page first, then its rows: the inner query picks the page's
            // sessions with the same filters and order but none of the
            // per-item CASE columns, so a results page pivots its sessions,
            // not the whole study. ROW_NUMBER is taken there, over every
            // matching session before the LIMIT, so the iteration fallback
            // stays study-wide.
            $pageSql = "SELECT `us`.`id` AS `page_id`, ROW_NUMBER() OVER (ORDER BY `us`.`id` ASC) AS `page_rn`
        $from" . $whereSql . "\n        GROUP BY " . implode(', ', $groupCols) . $having . $orderSql . $limitSql;
            $pageSelect = str_replace($iterationExpr, 'COALESCE(`us`.`study_iteration`, `page`.`page_rn`)', $select);
            $sql = "SELECT $pageSelect
        $from
        INNER JOIN ($pageSql) `page` ON `page`.`page_id` = `us`.`id`
        WHERE `i`.`study_id` = :pivot_study_id
        GROUP BY " . implode(', ', array_merge($groupCols, array('`page`.`page_rn`'))) . $orderSql;
            $bindings[':pivot_study_id'] = (int) $studyId;
            return array($sql, $bindings);
        }

        if ($paginate && isset($paginate['offset'])) {
            $sql .= sprintf("\n        LIMIT %d OFFSET %d", (int) $paginate['limit'], (int) $paginate['offset']);
        }

        return array($sql, $bindings);
    }

    /**
     * Get Results from the item display table
     *
     * @param array $items An array of item names that are required in the survey
     * @param string $session If specified, only results of that particular session will be returned
     * @param array $paginate Pagination parameters [offset, limit]
     * @param boolean $rstmt If TRUE, PDOStament will be returned instead
     * @return array|PDOStatement
     */
    public function getItemDisplayResults($items = array(), $filter = null, array $paginate = null, $rstmt = false)
    {
        ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

        $count = $this->getResultCount();
        if ($this->unlinked) {
            if ($count['real_users'] > 0) {
                alert("<strong>You cannot see the long-form results yet.</strong> It will only be possible after 10 real users have registered.", 'alert-warning');
            }
            return array();
        }

        $select = $this->db->select("`survey_run_sessions`.session,
		`survey_items_display`.`session_id` as `unit_session_id`,
		`survey_items_display`.`item_id`,
		`survey_items`.`name` as `item_name`,
		`survey_items`.`deleted` as `item_deleted`,
		`survey_items_display`.`answer`,
		`survey_items_display`.`created`,
		`survey_items_display`.`saved`,
		`survey_items_display`.`shown`,
		`survey_items_display`.`shown_relative`,
		`survey_items_display`.`answered`,
		`survey_items_display`.`answered_relative`,
		`survey_items_display`.`displaycount`,
		`survey_items_display`.`display_order`,
		`survey_items_display`.`hidden`");

        $select->from('survey_items_display')
            ->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
            ->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
            ->leftJoin('survey_items', 'survey_items_display.item_id = survey_items.id')
            ->where('survey_items.study_id = :study_id')
            // session is unique per run and session_id (the unit session) is
            // monotonic with unit-session creation, so this sorts identically
            // to the old (session, srs.created, sus.created, display_order)
            // chain with a much smaller filesort tuple
            ->order('survey_run_sessions.session')
            ->order('survey_items_display.session_id')
            ->order('survey_items_display.display_order')
            ->bindParams(array('study_id' => $this->id));

        if ($items) {
            $select->whereIn('survey_items.name', $items);
        }

        $session = array_val($filter, 'session', null);
        if ($session) {
            if (strlen($session) == 64) {
                $select->where("survey_run_sessions.session = :session");
            } else {
                $select->where("survey_run_sessions.session LIKE :session");
                $session .= "%";
            }
            $select->bindParams(array("session" => $session));
        }

        if ($paginate && isset($paginate['offset'])) {
            $select->limit($paginate['limit'], $paginate['offset']);
        }

        if ($rstmt === true) {
            return $select->statement();
        }
        return $select->fetchAll();
    }

    public function getResultsByItemsPerSession($items = array(), $filter = null, array $paginate = null, $rstmt = false)
    {
        if ($this->unlinked) {
            alert("You cannot view detailed results once you've unlinked a survey", 'alert-warning');
            return array();
        }
        ini_set('memory_limit', Config::get('memory_limit.survey_get_results'));

        $filter_select = $this->db->select('session_id');
        $filter_select->from($this->results_table);
        $filter_select->leftJoin('survey_unit_sessions', "{$this->results_table}.session_id = survey_unit_sessions.id");
        $filter_select->leftJoin('survey_run_sessions', 'survey_unit_sessions.run_session_id = survey_run_sessions.id');

        if (!empty($filter['session'])) {
            $session = $filter['session'];
            if (strlen($session) == 64) {
                $filter_select->where('survey_run_sessions.session = :session_eq');
                $filter_select->bindParams(array('session_eq' => $session));
            } else {
                $filter_select->like('survey_run_sessions.session', $session, 'right');
            }
        }

        if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
            $res_where = Template::replace($res_filter['query'], array('table' => $this->results_table));
            $filter_select->where($res_where);
        }
        $filter_select->order('session_id');
        if ($paginate && isset($paginate['offset'])) {
            $filter_select->limit($paginate['limit'], $paginate['offset']);
        }
        $stmt = $filter_select->statement();
        $session_ids = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $session_ids[] = (int) $row['session_id'];
        }
        // No session matches (a filter that finds nothing, a page past the
        // last): `IN ()` is a syntax error. Unit session ids start at 1, so
        // IN (0) matches nothing and the statement/array return stays as is.
        $session_ids = $session_ids ? implode(',', $session_ids) : '0';

        $select = $this->db->select("
		`survey_run_sessions`.session,
		`survey_items_display`.`session_id` as `unit_session_id`,
		`survey_items`.`name` as `item_name`,
		`survey_items_display`.`item_id`,
		`survey_items_display`.`answer`,
		`survey_items_display`.`created`,
		`survey_items_display`.`saved`,
		`survey_items_display`.`shown`,
		`survey_items_display`.`shown_relative`,
		`survey_items_display`.`answered`,
		`survey_items_display`.`answered_relative`,
		`survey_items_display`.`displaycount`,
		`survey_items_display`.`display_order`,
		`survey_items_display`.`hidden`");

        $select->from('survey_items_display')
            ->leftJoin('survey_unit_sessions', 'survey_unit_sessions.id = survey_items_display.session_id')
            ->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
            ->leftJoin('survey_items', 'survey_items.id = survey_items_display.item_id')
            ->where('survey_items.study_id = :study_id')
            ->where('survey_items_display.session_id IN (' . $session_ids . ')')
            ->order('survey_items_display.session_id')
            ->order('survey_items_display.display_order')
            ->bindParams(array('study_id' => $this->id));

        if ($items) {
            $select->whereIn('survey_items.name', $items);
        }

        if ($rstmt === true) {
            return $select->statement();
        }
        return $select->fetchAll();
    }

    /**
     * Get Results from the item display table
     *
     * @param array $items An array of item names that are required in the survey
     * @param array $sessions If specified, only results of that particular session will be returned
     * @return array
     */
    protected function hasData()
    {
        // Destructive gate (results-table recreate on re-upload): must read the
        // results table itself, never the write-time rollup — a stale/partial
        // survey_study_metrics row reading 0 here would DROP real data with no
        // backup. The rollup is for display only (getResultCount).
        $this->result_count = $this->getResultCountForGate();
        if (($this->result_count["real_users"] + $this->result_count['testers']) > 0) {
            return true;
        } else {
            return false;
        }
    }

    protected function hasRealData()
    {
        // Destructive gate (backup requirement, column-deletion confirmation):
        // live count only, same rationale as hasData().
        $this->result_count = $this->getResultCountForGate();
        if ($this->result_count["real_users"] > 1) {
            return true;
        } else {
            return false;
        }
    }

    public function deleteResults($run_id = null)
    {
        // Destructive gate: the "nothing to delete" short-circuit and the
        // backup-size message must reflect the actual table contents, not the
        // rollup (which can be stale or unseeded right after patch 069).
        $this->result_count = $this->getResultCountForGate($run_id);

        if (array_sum($this->result_count) === 0) {
            return true;
        } elseif ($run_id !== null) {
            //@todo implement deleting results only for a particular run
            $this->errors[] = 'Deleting run specific results for a survey is not yet implemented';
            return false;
        } elseif ($this->backupResults()) {

            // Delete results table
            $delete = $this->db->query("TRUNCATE TABLE `{$this->results_table}`");

            // Delete unit sessions/long format results. A Survey or Form
            // unit's sessions carry the study's id (shared primary key).
            // Their survey_items_display rows go with them (ON DELETE CASCADE).
            $this->db->exec(
                "DELETE FROM `survey_unit_sessions` WHERE unit_id = :study_id",
                array('study_id' => (int) $this->id)
            );
            $delete_item_disp = true;
            $this->restartIterations();

            // Reset the write-time rollup so display counts don't keep
            // reporting the deleted rows (and later hooks don't increment on a
            // stale base). Best-effort: the row re-seeds via hooks/reconcile.
            try {
                $this->db->delete('survey_study_metrics', array('study_id' => $this->id));
            } catch (Exception $e) {
                formr_log_exception($e, 'deleteResults StudyMetrics reset');
            }
            return $delete && $delete_item_disp;
        } else {
            $this->errors[] = __("Backup of %s result rows failed. Deletion cancelled.", array_sum($this->result_count));
            return false;
        }
    }

    /**
     * Number the study's sessions from 1 again, as the results table's
     * AUTO_INCREMENT did when "Delete results" truncated it or a re-upload
     * recreated it: `iteration` counts the responses to the survey. A
     * session that is still there keeps its number, and the allocator never
     * hands out one a session holds (UnitSession::allocateStudyIteration).
     */
    private function restartIterations()
    {
        $this->db->update('survey_studies', array('last_iteration' => 0), array('id' => (int) $this->id));
        $this->last_iteration = 0;
    }

    public function backupResults($itemNames = null)
    {
        // hasRealData() recomputes $this->result_count live; no separate
        // (rollup-backed) pre-count here — the backup decision and the row
        // count in the message must both come from the table itself.
        if ($this->hasRealData()) {
            $this->messages[] = __("<strong>Backed up.</strong> The old results were backed up in a file (%s results)", array_sum($this->result_count));

            $filename = $this->results_table . date('YmdHis') . ".tab";
            if (isset($this->user_id)) {
                $filename = "user" . $this->user_id . $filename;
            }
            $filename = APPLICATION_ROOT . "tmp/backups/results/" . $filename;

            // Back up the store the caller is about to truncate / ALTER. While
            // the wide table is written (dual-write on) it holds every session,
            // including legacy ones that have no survey_items_display rows and
            // so are absent from the long pivot getResults() reads by default.
            $rows = (Config::get('form_v2_dual_write_results', true) && $this->resultsTableExists())
                ? $this->getResultsWide($itemNames)
                : $this->getResults($itemNames);
            $SPR = new SpreadsheetReader();
            return $SPR->backupTSV($rows, $filename);
        } else { // if we have no real data, no need for backup
            return true;
        }
    }

    public function getResultCount($run_id = null, $filter = array())
    {
        // Unscoped count comes from the write-time study rollup (audit SQ-11):
        // fresh via the survey start/complete hooks, O(1) read. Per-object cache
        // dedupes repeat calls; scoped/filtered variants always hit the DB live.
        // DISPLAY ONLY: bounded staleness is acceptable here. Anything that
        // gates deletion or other destructive changes must use
        // getResultCountLive() instead.
        // A filter left blank filters nothing (the results page always sends
        // `session` and `results`); for a v2 study the live count groups
        // every display row of the study.
        $filter = array_filter((array) $filter, function ($value) {
            return $value !== null && $value !== '';
        });
        if (!$filter && !$run_id) {
            if ($this->result_count !== null) {
                return $this->result_count;
            }
            if ($this->id && ($rollup = StudyMetrics::counts((int) $this->id)) !== null) {
                $this->result_count = $rollup;
                return $rollup;
            }
            // no rollup row yet (brand-new study) — fall through to the live
            // query below, which is cheap for a study with little/no data
        }

        return $this->getResultCountLive($run_id, $filter);
    }

    /**
     * Ground-truth result count straight from the results table, bypassing the
     * survey_study_metrics rollup AND the per-object cache. Required by every
     * destructive gate (hasData/hasRealData/deleteResults/backupResults): the
     * rollup can be unseeded (fresh patch 069), partial (first hook fires
     * before the first reconcile) or drifted (imports, testing toggles), and a
     * false zero here means dropping participant data without a backup.
     * Refreshes the cache so subsequent display reads see the live value.
     */
    public function getResultCountLive($run_id = null, $filter = array())
    {
        $count = array('finished' => 0, 'begun' => 0, 'testers' => 0, 'real_users' => 0);

        $count = self::readsLong($this->rendering_mode)
            ? $this->getResultCountLong($run_id, $filter, $count)
            : $this->getResultCountWide($run_id, $filter, $count);

        if (!$filter && !$run_id) {
            $this->result_count = $count;
        }

        return $count;
    }

    /**
     * The count the destructive gates (hasData, hasRealData, deleteResults,
     * backupResults) decide on. With long reads the live count comes from
     * survey_items_display, which does not hold every session the wide table
     * does: legacy rows (imports without display rows, restored backups) are
     * only in the wide table, and a zero from the long count would let a
     * re-upload DROP that table without a backup. So while the wide table
     * exists, each bucket is the larger of the two counts.
     *
     * @param int|null $run_id
     * @return array{finished:int, begun:int, testers:int, real_users:int}
     */
    protected function getResultCountForGate($run_id = null)
    {
        $count = $this->getResultCountLive($run_id);
        if ((self::readsLong($this->rendering_mode)) && $this->resultsTableExists()) {
            $wide = $this->getResultCountWide($run_id, array(), array('finished' => 0, 'begun' => 0, 'testers' => 0, 'real_users' => 0));
            foreach ($count as $k => $v) {
                $count[$k] = max((int) $v, (int) (is_array($wide) && isset($wide[$k]) ? $wide[$k] : 0));
            }
        }
        return $count;
    }

    /**
     * Result counts read from the per-study wide results table ($count when
     * the table does not exist).
     */
    private function getResultCountWide($run_id, $filter, array $count)
    {
        if ($this->resultsTableExists()) {
            $results_table = $this->results_table;
            $select = $this->db->select(array(
                "SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0 AND `{$results_table}`.ended IS null)" => 'begun',
                "SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0 AND `{$results_table}`.ended IS NOT NULL)" => 'finished',
                "SUM(`survey_run_sessions`.`testing` IS NULL OR `survey_run_sessions`.`testing` = 1)" => 'testers',
                "SUM(`survey_run_sessions`.`testing` IS NOT NULL AND `survey_run_sessions`.`testing` = 0)" => 'real_users'
            ))->from($results_table)
                ->leftJoin('survey_unit_sessions', "survey_unit_sessions.id = {$results_table}.session_id")
                ->leftJoin('survey_run_sessions', "survey_unit_sessions.run_session_id = survey_run_sessions.id");

            if ($run_id) {
                $select->where(array('survey_run_sessions.run_id' => (int) $run_id));
            }
            if (!empty($filter['session'])) {
                $session = $filter['session'];
                if (strlen($session) == 64) {
                    $select->where('survey_run_sessions.session = :session_eq');
                    $select->bindParams(array('session_eq' => $session));
                } else {
                    $select->like('survey_run_sessions.session', $session, 'right');
                }
            }

            if (!empty($filter['results']) && ($res_filter = $this->getResultsFilter($filter['results']))) {
                $res_where = Template::replace($res_filter['query'], array('table' => $results_table));
                $select->where($res_where);
            }

            $count = $select->fetch();
        }

        return $count;
    }

    /**
     * Long-format result counts. A session "has begun" this study when it has
     * survey_items_display rows for it — those are written by the same
     * createSurveyStudyRecord call that used to create the wide row, so the
     * row set matches the wide count's. ended comes from
     * survey_unit_sessions (end() stamps both in the same pass).
     */
    private function getResultCountLong($run_id, $filter, array $count)
    {
        $wheres = array();
        $bindings = array(':study_id' => (int) $this->id);
        if ($run_id) {
            $wheres[] = '`rs`.`run_id` = :run_id';
            $bindings[':run_id'] = (int) $run_id;
        }
        if (!empty($filter['session'])) {
            $wheres[] = self::longSessionFilter($filter['session'], $bindings);
        }
        // Canonical results filters, long semantics (see buildPivotSql) —
        // including the same operator-force-move rule for `ended` (F4), so the
        // counts above the results table agree with the rows in it.
        $endedExpr = UnitSession::completedEndedSql('us');
        if (!empty($filter['results']) && $filter['results'] === 'complete') {
            $wheres[] = $endedExpr . ' IS NOT NULL';
        } elseif (!empty($filter['results']) && $filter['results'] === 'incomplete') {
            $wheres[] = '(`t`.`first_created` <> `t`.`last_saved` OR `t`.`last_saved` IS NULL) AND ' . $endedExpr . ' IS NULL';
        }

        $sql = 'SELECT
            SUM(`rs`.`testing` IS NOT NULL AND `rs`.`testing` = 0 AND ' . $endedExpr . ' IS NULL) AS `begun`,
            SUM(`rs`.`testing` IS NOT NULL AND `rs`.`testing` = 0 AND ' . $endedExpr . ' IS NOT NULL) AS `finished`,
            SUM(`rs`.`testing` IS NULL OR `rs`.`testing` = 1) AS `testers`,
            SUM(`rs`.`testing` IS NOT NULL AND `rs`.`testing` = 0) AS `real_users`
        FROM (
            SELECT `sid`.`session_id`, MIN(`sid`.`created`) AS `first_created`, MAX(`sid`.`saved`) AS `last_saved`
            FROM `survey_items_display` `sid`
            INNER JOIN `survey_items` `i` ON `i`.`id` = `sid`.`item_id`
            WHERE `i`.`study_id` = :study_id
            GROUP BY `sid`.`session_id`
        ) `t`
        INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `t`.`session_id`
        LEFT JOIN `survey_run_sessions` `rs` ON `rs`.`id` = `us`.`run_session_id`';
        if ($wheres) {
            $sql .= "\n        WHERE " . implode(' AND ', $wheres);
        }

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            // SUM over an empty set is NULL — keep the zero defaults then.
            foreach ($count as $k => $zero) {
                $count[$k] = $row[$k] !== null ? $row[$k] : 0;
            }
        }
        return $count;
    }

    /**
     * Geometric-mean completion duration in minutes, or null if no completions
     * (audit SQ-10 replacement). Read O(1) from the write-time study rollup:
     * EXP(mean(ln seconds)) — maintainable incrementally where a median is not,
     * and a better central tendency for right-skewed completion times.
     */
    public function getGeometricMeanDurationMinutes()
    {
        if (!$this->id) {
            return null;
        }
        $seconds = StudyMetrics::geometricMeanSeconds((int) $this->id);
        return $seconds === null ? null : round($seconds / 60, 1);
    }

    public function delete()
    {
        if ($this->deleteResults()) {
            $this->db->query("DROP TABLE IF EXISTS `{$this->results_table}`");
            if (($filename = $this->getOriginalFileName())) {
                @unlink(Config::get('survey_upload_dir') . '/' . $filename);
            }

            $this->db->query('DELETE FROM survey_items WHERE study_id = ' . $this->id);
            return $this->db->query('DELETE FROM survey_units WHERE id = ' . $this->id);
        }

        return false;
    }

    /**
     * Fetch all uploaded files associated with this study
     */
    public function getUploadedFiles()
    {
        return $this->db->select(['id', 'study_id', 'unit_session_id', 'original_filename', 'created', 'stored_path'])
            ->from('user_uploaded_files')
            ->where(['study_id' => $this->id]);
    }

    /**
     * Delete all uploaded files associated with this study
     */
    protected function deleteUploadedFiles()
    {
        $files = $this->getUploadedFiles()->fetchAll();

        foreach ($files as $file) {
            $filepath = APPLICATION_ROOT . $file['stored_path'];
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }
    }

    /**
     * Merge survey items. Each parameter is an associative array indexed by the names of the items in the survey, with the 
     * mysql field definition as the value.
     * new items are added, old items are deleted, items that changed type are deleted from the results table but not the item_display_table
     * All non null entries represent the MySQL data type definition of the fields as they should be in the survey results table
     * NOTE: All the DB queries here should be in a transaction of calling function
     *
     * @param array $newItems
     * @param array $deleteItems
     * @return bool;
     */
    /**
     * Give a wide table from before 2024-07 its `iteration` column without
     * renumbering anyone. A session numbered since patch 108 brings its
     * study_iteration into its wide row; the other rows are numbered above
     * every number handed out, in the order they began, and their sessions
     * get the same number. Only then does the column become AUTO_INCREMENT
     * UNIQUE, as getResultsTableSyntax() defines it. Adding it as
     * AUTO_INCREMENT straight away numbered every existing row 1..N, so a
     * session numbered since patch 108 had one iteration in the wide table
     * and another in study_iteration, and the backfill then gave the older
     * sessions numbers those sessions held.
     */
    private function addIterationColumn()
    {
        $table = str_replace('`', '``', $this->results_table);
        $id = (int) $this->id;
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `iteration` INT UNSIGNED NULL");
        $this->db->query("UPDATE `{$table}` `w` JOIN `survey_unit_sessions` `us` ON `us`.`id` = `w`.`session_id`
            SET `w`.`iteration` = `us`.`study_iteration`
            WHERE `us`.`study_iteration` IS NOT NULL AND `w`.`iteration` IS NULL");
        $base = (int) $this->db->execute("SELECT GREATEST(
                (SELECT COALESCE(MAX(`last_iteration`), 0) FROM `survey_studies` WHERE `id` = {$id}),
                (SELECT COALESCE(MAX(`study_iteration`), 0) FROM `survey_unit_sessions` WHERE `unit_id` = {$id}),
                (SELECT COALESCE(MAX(`iteration`), 0) FROM `{$table}`))", array(), true);
        $this->db->query("UPDATE `{$table}` `w`
            JOIN (SELECT `session_id`, ROW_NUMBER() OVER (ORDER BY `created`, `session_id`) AS `n`
                    FROM `{$table}` WHERE `iteration` IS NULL) `r` ON `r`.`session_id` = `w`.`session_id`
            SET `w`.`iteration` = {$base} + `r`.`n`");
        $this->db->query("UPDATE `survey_unit_sessions` `us` JOIN `{$table}` `w` ON `w`.`session_id` = `us`.`id`
            SET `us`.`study_iteration` = `w`.`iteration`
            WHERE `us`.`study_iteration` IS NULL AND `us`.`unit_id` = {$id}");
        $this->db->query("ALTER TABLE `{$table}` MODIFY `iteration` INT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE");
        $this->db->query("UPDATE `survey_studies`
            SET `last_iteration` = GREATEST(`last_iteration`, (SELECT COALESCE(MAX(`iteration`), 0) FROM `{$table}`))
            WHERE `id` = {$id}");
    }

    private function alterResultsTable(array $newItems, array $deleteItems)
    {
        $actions = $toAdd = $toDelete = array();
        $deleteQuery = $addQuery = array();
        $addQ = $delQ = null;

        // just for safety checking that there is something to be deleted (in case of aborted earlier tries)
        $existingColumns = $this->db->getTableDefinition($this->results_table, 'Field');

        // Before 2024-07 an item could be named `iteration` (the reader
        // refuses the name since), so a table that old may hold that item's
        // answers in an `iteration` column. A re-upload deletes the item; its
        // column goes first, so the heal below adds the real one instead of
        // numbering the answers.
        if (isset($existingColumns['iteration']) && isset($deleteItems['iteration'])) {
            $this->db->query("ALTER TABLE `{$this->results_table}` DROP `iteration`");
            unset($existingColumns['iteration'], $deleteItems['iteration']);
            $actions[] = "Deleted columns: iteration.";
        }

        // Legacy-host heal (F2): a wide table created before 2024-07 has no
        // `iteration` column. Add it here, on the one code path that already
        // ALTERs this table (a re-upload by the study owner), rather than at
        // request time for a participant. Also when an earlier attempt added
        // the column but stopped before it became AUTO_INCREMENT.
        if ($existingColumns && (!isset($existingColumns['iteration'])
                || stripos((string) $existingColumns['iteration']['Extra'], 'auto_increment') === false)) {
            $this->addIterationColumn();
            $actions[] = "Added the missing `iteration` column, keeping each session's number.";
            self::forgetWideTableColumns($this->results_table);
        }

        // Create query to drop items in existing table
        foreach ($deleteItems as $name => $result_field) {
            if ($result_field !== null && isset($existingColumns[$name])) {
                $deleteQuery[] = " DROP `{$name}`";
            }
            $toDelete[] = $name;
        }
        // Create query for adding items to existing table
        foreach ($newItems as $name => $result_field) {
            if ($result_field !== null) {
                $addQuery[] = " ADD $result_field";
            }
            $toAdd[] = $name;
        }

        // prepare these strings for feedback
        $added_columns_string = implode(", ", $toAdd);
        $deleted_columns_string = implode(", ", $toDelete);


        // if something should be deleted
        if ($deleteQuery) {
            $q = "ALTER TABLE `{$this->results_table}`" . implode(',', $deleteQuery);
            $this->db->query($q);
            $actions[] = "Deleted columns: $deleted_columns_string.";
        }

        // we only get here if the deletion stuff was harmless, allowed or did not happen
        if ($addQuery) {
            $q = "ALTER TABLE `{$this->results_table}`" . implode(',', $addQuery);
            $this->db->query($q);
            $actions[] = "Added columns: $added_columns_string.";
        }

        if (!empty($actions)) {
            self::forgetWideTableColumns($this->results_table);
            $this->messages[] = "<strong>The results table was modified.</strong>";
            $this->messages = array_merge($this->messages, $actions);
        }

        return true;
    }

    public function getOriginalFileName()
    {
        return $this->original_file;
        //return $this->db->findValue('survey_studies', array('id' => $this->id), 'original_file');
    }

    public function getGoogleFileId()
    {
        return $this->google_file_id;
        //return $this->db->findValue('survey_studies', array('id' => $this->id), 'google_file_id');
    }

    public function getResultsFilter($f = null)
    {
        $filter = array(
            'all' => array(
                'title' => 'Show All',
                'query' => null,
            ),
            'incomplete' => array(
                'title' => 'Incomplete',
                'query' => '(%{table}.created <> %{table}.modified or %{table}.modified is null) and %{table}.ended is null',
            ),
            'complete' => array(
                'title' => 'Complete',
                'query' => '%{table}.ended is not null',
            ),
        );

        return $f !== null ? array_val($filter, $f, null) : $filter;
    }

    public function getOrderedItemsIds()
    {
        $get_items = $this->db->select('
				`survey_items`.id,
				`survey_items`.`type`,
				`survey_items`.`item_order`,
				`survey_items`.`block_order`')
            ->from('survey_items')
            // soft-deleted items (patch 112) get no new survey_items_display rows
            ->where("`survey_items`.`study_id` = :study_id AND `survey_items`.`deleted` IS NULL")
            ->order("`survey_items`.order")
            ->bindParams(array('`study_id`' => $this->id))
            ->statement();

        // sort blocks randomly (if they are consecutive), then by item number and if the latter are identical, randomly
        $block_segment = $block_order = $item_order = $random_order = $block_numbers = $item_ids = array();
        $types = array();

        $last_block = "";
        $block_nr = 0;
        $block_segment_i = 0;

        while ($item = $get_items->fetch(PDO::FETCH_ASSOC)) {
            if ($item['block_order'] == "") { // not blocked
                $item['block_order'] = ""; // ? why is this necessary
                $block_order[] = $block_nr;
            } else {
                if (!array_key_exists($item['block_order'], $block_numbers)) { // new block
                    if ($last_block === "") { // new segment of blocks
                        $block_segment_i = 0;
                        $block_segment = range($block_nr, $block_nr + 10000); // by choosing this range, the next non-block segment is forced to follow
                        shuffle($block_segment);
                        $block_nr = $block_nr + 10001;
                    }

                    $rand_block_number = $block_segment[$block_segment_i];
                    $block_numbers[$item['block_order']] = $rand_block_number;
                    $block_segment_i++;
                }
                $block_order[] = $block_numbers[$item['block_order']]; // get stored block order
            } // sort the blocks with each other
            // but keep the order within blocks if desired
            $item_order[] = $item['item_order']; // after sorting by block, sort by item order 
            $item_ids[] = $item['id'];
            $last_block = $item['block_order'];

            $types[$item['id']] = $item['type'];
        }

        $random_order = range(1, count($item_ids)); // if item order is identical, sort randomly (within block)
        shuffle($random_order);
        array_multisort($block_order, $item_order, $random_order, $item_ids);
        // order is already sufficiently defined at least by random_order, but this is a simple way to sort $item_ids is sorted accordingly

        return array($item_ids, $types);
    }

    public function getSettings()
    {
        $keys = [
            'maximum_number_displayed',
            'displayed_percentage_maximum',
            'add_percentage_points',
            'enable_instant_validation',
            'expire_after',
            'google_file_id',
            'unlinked',
            'expire_invitation_after',
            'expire_invitation_grace',
            'hide_results',
            'use_paging',
            // v2 engine settings; createFromData validates them on import.
            'rendering_mode',
            'offline_mode',
            'allow_previous',
            'layout',
            'language',
            'next_button_label',
            'submit_button_label',
        ];
        $settings = [];

        foreach ($keys as $key) {
            $settings[$key] = $this->{$key} ?? null;
        }

        return $settings;
    }
}
