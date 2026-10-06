<?php

class ItemFactory {

    public $errors;
    public $showifs = array();
    public $openCPU_errors = array();
    private $choice_lists = array();
    private $used_choice_lists = array();

    function __construct($choice_lists) {
        $this->choice_lists = $choice_lists;
    }

    public function make($item) {
        $type = "";
        if (isset($item['type'])) {
            $type = $item['type'];
        }

        if (!empty($item['choice_list'])) { // if it has choices
            if (isset($this->choice_lists[$item['choice_list']])) { // if this choice_list exists
                $item['choices'] = $this->choice_lists[$item['choice_list']]; // take it
                $this->used_choice_lists[] = $item['choice_list']; // check it as used
            } elseif ($item['choice_list'] !== '*') {
                // '*' is the wildcard sentinel for items that supply their own
                // choices (e.g. timezone → timezone_identifiers_list()). It
                // legitimately has no entry in the choices sheet, so don't treat
                // it as a missing list — this is what lets a JSON survey export
                // (which stores choice_list="*") round-trip back in.
                $item['val_errors'] = array(__("Choice list %s does not exist, but is specified for item %s", $item['choice_list'], $item['name']));
            }
        }

        $type = str_replace('-', '_', $type);
        $class = $this->getItemClass($type);

        if (!class_exists($class, true)) {
            return false;
        }

        return new $class($item);
    }

    public function unusedChoiceLists() {
        return array_diff(
                array_keys($this->choice_lists), $this->used_choice_lists
        );
    }

    protected function getItemClass($type) {
        $parts = explode('_', $type);
        $parts = array_map('ucwords', $parts);
        $item = implode('', $parts);
        return $item . '_Item';
    }

}

/**
 * HTML Item
 * The default item is a text input, as many browser render any input type they don't understand as 'text'.
 * The base class should also work for inputs like date, datetime which are either native or polyfilled but don't require special handling here
 * 
 */
class Item {

    /**
     * The helpers a transpiled showif may call in the browser (the fmrForm
     * component's methods, webroot/assets/form/js/showif/alpine.js). One
     * list: RESERVED_NAMES reserves them as item names and
     * FormV2CompatScanner treats them as known identifiers.
     */
    const SHOWIF_HELPERS = array('isNA', 'answered', 'contains', 'containsWord', 'startsWith', 'endsWith', 'last');

    /**
     * The wide results table's own columns (SurveyStudy::getResultsTableSyntax).
     * MariaDB compares column names without regard to case, so `Created` is
     * the `created` column as well.
     */
    const RESULTS_TABLE_COLUMNS = array('session_id', 'study_id', 'iteration', 'created', 'modified', 'ended', 'expired');

    /** Never an item name, on any engine: the results table's columns, and `id`. */
    const RESULT_COLUMN_NAMES = array(...self::RESULTS_TABLE_COLUMNS, 'id');

    /** Is $name one of RESULT_COLUMN_NAMES — a results table column in any case? */
    public static function isResultColumnName($name) {
        $name = (string) $name;
        return in_array($name, self::RESULT_COLUMN_NAMES, true)
            || in_array(strtolower($name), self::RESULTS_TABLE_COLUMNS, true);
    }

    /**
     * Item names that may never be used. Item::validate() (per item, JSON
     * imports included) checks against this list; SpreadsheetReader (per
     * row, at upload) checks the RESULT_COLUMN_NAMES part.
     *
     * Two groups:
     *   - results-table / session columns (`id`, `session_id`, `created`,
     *     `modified`, `ended`, `expired`, `study_id`, `iteration`, in any case
     *     but `id`) — an item of that name would collide with the wide
     *     results table's own columns.
     *   - the JS helpers the form_v2 showif evaluator exposes
     *     (webroot/assets/form/js/showif/alpine.js). Item values are top-level
     *     fields on the same Alpine component as those helpers, because the
     *     transpiled showif references item names bare. An item named
     *     `answered` would shadow the method, every showif calling it would
     *     throw, and — since the directive treats an unevaluable expression as
     *     "keep the server's decision" — those showifs would silently freeze.
     *     `isNA` is on the list because that is what `is.na()` transpiles to.
     */
    const RESERVED_NAMES = array(
        ...self::RESULT_COLUMN_NAMES,
        ...self::FORM_COMPONENT_NAMES,
    );

    /**
     * What a v2 item's value would shadow on the form's Alpine component
     * (alpine.js RESERVED_FIELD_NAMES, which never writes such a field): the
     * showif helpers and the component's own init(). A showif reading an
     * item of such a name reads the method instead. Reserved on v2 only
     * (legacyHelperName).
     */
    const FORM_COMPONENT_NAMES = array(...self::SHOWIF_HELPERS, 'init');

    /**
     * The showif helper names (`last`, `answered`, …) are reserved on form_v2
     * only: a v1 study never runs those helpers, so SurveyStudy::addItems
     * sets this for every item of a v1 study and validate() then checks only
     * RESULT_COLUMN_NAMES. On v2 they stay reserved, and the switch to v2
     * refuses a study that has one (SurveyStudy::formV2UnsupportedItems).
     *
     * @var bool
     */
    protected $legacyHelperName = false;

    public function allowLegacyHelperName() {
        $this->legacyHelperName = true;
    }

    public $id = null;
    public $name = null;
    public $type = null;
    public $type_options = null;
    public $choice_list = null;
    public $label = null;
    public $label_parsed = null;
    public $optional = 0;
    public $class = null;
    public $showif = null;
    public $js_showif = null;
    public $value = null; // syntax for sticky value
    public $value_validated = null;
    public $order = null;
    public $block_order = null;
    public $item_order = null;
    public $displaycount = null;
    public $error = null;

    /**
     * Whether a reply may be an array (several values). False for every
     * scalar input; the multi-valued types (mc_multiple, select_multiple,
     * select_or_add_multiple, file, geopoint) set it to true. Consulted by
     * rejectNonScalarReply(), which UnitSession runs on every posted value
     * before validateInput() / getReply() see it.
     */
    protected $accepts_array_reply = false;

    public function acceptsArrayReply() {
        return (bool) $this->accepts_array_reply;
    }

    /**
     * Validating a reply has side effects: a bot_check spends its one-shot
     * token, an upload is moved into place. Such an item is judged once, at
     * a submit, after everything else posted has passed
     * (UnitSession::updateSurveyStudyRecord), and never on autosave
     * (FormSubmissionService::autosaveEligible).
     */
    protected $validation_has_side_effects = false;

    public function validationHasSideEffects() {
        return (bool) $this->validation_has_side_effects;
    }

    /**
     * The answer to store instead of validating this reply again: one the
     * unit session already holds that settles the item (BotCheck_Item: a
     * check passed once). null validates the reply as usual. Asked of an
     * item whose validation has side effects, before it runs.
     */
    public function settledAnswer(UnitSession $unitSession) {
        return null;
    }

    /**
     * After a write that validated this item's reply with side effects:
     * $stored says whether the page was stored. An item whose page was not
     * stored undoes what its validation did (BotCheck_Item gives its token
     * back).
     */
    public function finishSideEffects($stored) {
    }

    /** Only the form_v2 engine can run this item type (v1Unsupported). */
    protected $v2_only = false;

    /**
     * An item participants never see, although it is on the page and posted
     * with it (data_quality, agent_probe). It takes no part in deciding
     * whether a page has anything to show: a page of nothing else is skipped
     * (v1: SpreadsheetRenderer, PagedSpreadsheetRenderer; v2: the wrapper's
     * `data-fmr-unseen`, read by snapshotPages in main.js), and it is no solo
     * step, no item to answer for the all-answered auto-submit and none in
     * the progress bar. A `hidden` item is not one of these: it keeps its
     * page, as it always has.
     */
    protected $unseen = false;

    public function isUnseen() {
        return (bool) $this->unseen;
    }

    /**
     * Can the form show this item's stored answer as a preset when it is
     * rendered again? Not an upload (a file input takes no value), a
     * bot_check (its stored marker is not a token) or a submit button.
     */
    public function rendersStoredAnswer() {
        return (bool) $this->save_in_results_table;
    }

    /**
     * A stored answer (survey_items_display.answer) in the shape the live
     * inputs produce, for the form_v2 state block: a multi-valued reply is
     * stored joined by ", " and goes back as an array.
     *
     * @param string|null $answer
     * @return mixed
     */
    public function storedValueForClient($answer) {
        if ($answer !== null && $this->acceptsArrayReply()) {
            return ($answer === '') ? array() : explode(', ', (string) $answer);
        }
        return $answer;
    }
    /**
     * Set on the items of a form_v2 study when they are made
     * (SurveyStudy::getItemsWithChoices, FormRenderer). v2 stores a multiple
     * choice's value "0" (replyValues); v1 keeps dropping it, as it always
     * has, so results of running v1 studies do not change shape. A choice
     * list written in one cell is split at ";" in v2, at "," in v1
     * (SelectOrAddOne_Item::unpackChoices).
     */
    public $formV2 = false;

    /**
     * The values of a multi-valued reply that are answers: the empty
     * placeholder the form posts is never one. On v2 "0" is a value like any
     * other; v1 drops it too (array_filter), which is how its results have
     * always looked.
     */
    protected function replyValues(array $reply) {
        if (!$this->formV2) {
            return array_filter($reply);
        }
        return array_values(array_filter($reply, function ($v) {
            return $v !== '' && $v !== null;
        }));
    }

    /**
     * Width of a column that stores every choice key joined by ", " — the
     * longest a multiple-choice answer can be. "0" counts (v2 stores it).
     */
    protected static function joinedChoicesLength(array $keys) {
        $keys = array_filter($keys, function ($k) { return $k !== '' && $k !== null; });
        return max(1, strlen(implode(', ', $keys)));
    }

    public $dont_validate = null;
    public $reply = null;
    public $val_errors = array();
    public $val_warnings = array();
    public $mysql_field = 'TEXT DEFAULT NULL';
    public $choices = array();
    public $hidden = false;
    public $no_user_input_required = false;
    public $save_in_results_table = true;
    public $input_attributes = array(); // so that the pre-set value can be set externally
    public $parent_attributes = array();
    public $presetValue = null;
    public $allowed_classes = array();
    public $skip_validation = false;
    public $data_showif = false;
    public $val_error;
    public $openCPU_errors;

    protected $prepend = null;
    protected $append = null;
    protected $type_options_array = array();
    protected $hasChoices = false;
    // Choices may be provided (e.g. to override a button label) but are not
    // required — exempts the item from both directions of the choices
    // validation. Used by the PWA prompt items.
    protected $choicesOptional = false;
    protected $classes_controls = array('controls');
    protected $classes_wrapper = array('form-group', 'form-row');
    protected $classes_input = array();
    protected $classes_label = array('control-label');
    protected $presetValues = array();
    protected $probably_render = null;
    protected $js_hidden = false;
    // a11y: when an item is a group of related controls sharing one stem
    // (radio / checkbox sets), choice subclasses set this to 'radiogroup' or
    // 'group'. render_inner() then puts the role + aria-labelledby (pointing at
    // the stem label's id) on the .controls wrapper so a screen reader announces
    // the group stem on entry. Literal <fieldset>/<legend> would fight the
    // BS3-derived flex/grid CSS and the button-group/showif JS that target the
    // current .control-label/.controls structure, so we use ARIA grouping
    // instead — same SR outcome, no layout/JS breakage.
    protected $group_role = null;

    /**
     * Minimized attributes
     *
     * @var array
     */
    protected $_minimizedAttributes = array(
        'compact', 'checked', 'declare', 'readonly', 'disabled', 'selected',
        'defer', 'ismap', 'nohref', 'noshade', 'nowrap', 'multiple', 'noresize',
        'autoplay', 'controls', 'loop', 'muted', 'required', 'novalidate', 'formnovalidate'
    );

    /**
     * Format to attribute
     *
     * @var string
     */
    protected $_attributeFormat = '%s="%s"';

    /**
     * Format to attribute
     *
     * @var string
     */
    protected $_minimizedAttributeFormat = '%s="%s"';

    public function __construct($options = array()) {
        // Load options to object
        $optional = $this->optional;
        foreach ($options as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
        // A blank choice_list — from a sheet cell, a JSON export, or a DB row
        // the pre-fix reader wrote — means "no list". Only null reads that way
        // in validate(), which would otherwise reject every non-choice item in a
        // sheet that merely has the column.
        if ($this->choice_list === '') {
            $this->choice_list = null;
        }

        // Assign needed defaults
        $this->id = isset($options['id']) ? $options['id'] : 0;
        $this->label = isset($options['label']) ? $options['label'] : '';
        $this->label_parsed = isset($options['label_parsed']) ? $options['label_parsed'] : null;
        $this->allowed_classes = Config::get('css_classes', array());

        if (isset($options['type_options'])) {
            $this->type_options = trim($options['type_options']);
            $this->type_options_array = array($options['type_options']);
        }

        if (empty($this->choice_list) && $this->hasChoices && $this->type_options != '') {
            $lc = explode(' ', trim($this->type_options));
            if (count($lc) > 1) {
                $this->choice_list = end($lc);
            }
        }

        if (!empty($options['val_error'])) {
            $this->val_error = $options['val_error'];
        }

        if (!empty($options['error'])) {
            $this->error = $options['error'];
            $this->classes_wrapper[] = "has-error";
        }

        if (isset($options['displaycount']) && $options['displaycount'] !== null) {
            $this->displaycount = $options['displaycount'];
        }

        $this->input_attributes['name'] = $this->name;

        $this->setMoreOptions();

        // after the easily overridden setMoreOptions, some post-processing that is universal to all items.
        if ($this->type == 'note') {
            // notes can not be "required"
            unset($options['optional']);
        }

        if (isset($options['optional']) && $options['optional']) {
            $this->optional = 1;
            unset($options['optional']);
        } elseif (isset($options['optional']) && !$options['optional']) {
            $this->optional = 0;
        } else {
            $this->optional = $optional;
        }

        if (!$this->optional) {
            $this->classes_wrapper[] = 'required';
            $this->input_attributes['required'] = 'required';
        } else {
            $this->classes_wrapper[] = 'optional';
        }

        if (!empty($options['class'])) {
            $this->classes_wrapper = array_merge($this->classes_wrapper, explode(' ', $options['class']));
            $this->class = $options['class'];
        }

        $this->classes_wrapper[] = 'item-' . $this->type;

        if (!isset($this->input_attributes['type'])) {
            $this->input_attributes['type'] = $this->type;
        }

        $this->input_attributes['class'] = implode(' ', $this->classes_input);

        $this->input_attributes['id'] = "item{$this->id}";

        if (in_array('label_as_placeholder', $this->classes_wrapper)) {
            $this->input_attributes['placeholder'] = $this->label;
        }

        if ($this->showif) {
            $this->js_showif = self::showifToJs($this->showif);
            if (strstr($this->showif, "//js_only") !== false) {
                $this->setVisibility(array(null));
            }
        }
    }

    /**
     * v1's live preview of an R showif: a regex translation of the common R
     * idioms into JS (survey.js evaluates it; the server evaluates the R).
     * Anything else passes through and fails in JS. form_v2 does not use it
     * — there a showif IS JavaScript (useVerbatimShowif).
     */
    public static function showifToJs($showif) {
        $js = preg_replace("/current\(\s*(\w+)\s*\)/", "$1", $showif); // remove current function
        $js = preg_replace("/tail\(\s*(\w+)\s*, 1\)/", "$1", $js); // remove current function, JS evaluation is always in session
        // all other R functions may break
        $js = preg_replace("/(^|[^&])(\&)([^&]|$)/", "$1&$3", $js); // & operators, only single ones need to be doubled
        $js = preg_replace("/(^|[^|])(\|)([^|]|$)/", "$1|$3", $js); // | operators, only single ones need to be doubled
        $js = preg_replace("/FALSE/", "false", $js); // uppercase, R, FALSE, to lowercase, JS, false
        $js = preg_replace("/TRUE/", "true", $js); // uppercase, R, TRUE, to lowercase, JS, true
        $quoted_string = "([\"'])((\\\\{2})*|(.*?[^\\\\](\\\\{2})*))\\1";
        $js = preg_replace("/\s*\%contains\%\s*" . $quoted_string . "/", ".toString().indexOf($1$2$1) > -1", $js);
        $js = preg_replace("/\s*\%contains_word\%\s*" . $quoted_string . "/", ".toString().match(/\\b$2\\b/) !== null", $js);
        $js = preg_replace("/\s*\%begins_with\%\s*" . $quoted_string . "/", ".toString().indexOf($1$2$1) === 0", $js);
        $js = preg_replace("/\s*\%starts_with\%\s*" . $quoted_string . "/", ".toString().indexOf($1$2$1) === 0", $js);
        $js = preg_replace("/\s*\%ends_with\%\s*" . $quoted_string . "/", ".toString().endsWith($1$2$1)", $js);
        $js = preg_replace("/\s*stringr::str_length\(([a-zA-Z0-9_'\"]+)\)/", "$1.length", $js);
        $js = preg_replace("/\s*is.na\(([a-zA-Z0-9_'\"]+)\)/", "(typeof($1) === 'undefined')", $js);
        return $js;
    }

    /**
     * form_v2: the showif column is JavaScript, used as written — no R
     * translation (FormRenderer calls this on every item it renders).
     */
    public function useVerbatimShowif() {
        $this->js_showif = trim((string) $this->showif) !== '' ? (string) $this->showif : null;
    }

    public function refresh($options = array(), $properties = array()) {
        foreach ($properties as $property) {
            if (property_exists($this, $property) && isset($options[$property])) {
                $this->{$property} = $options[$property];
            }
        }

        $this->setMoreOptions();
        $this->classes_wrapper = array_merge($this->classes_wrapper, array('item-' . $this->type));
        return $this;
    }

    public function hasBeenRendered() {
        return $this->displaycount !== null;
    }

    public function hasBeenViewed() {
        return $this->displaycount > 0;
    }

    protected function chooseResultFieldBasedOnChoices() {
        if ($this->mysql_field == null) {
            return;
        }
        $choices = array_keys($this->choices);

        $len = count($choices);
        if ($len == count(array_filter($choices, 'is_numeric'))) {
            $this->mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';

            $min = min($choices);
            $max = max($choices);

            if ($min < 0) {
                $this->mysql_field = str_replace('UNSIGNED ', '', $this->mysql_field);
            }

            if (count(array_filter($choices, function ($c) { return (float) $c != (int) $c; }))) {
                // Choice keys are ints or strings ("0.5" stays a string key),
                // so is_float never matched and decimal codes were rounded.
                // Checked first: a decimal code of any size needs a FLOAT
                // (150.5 must not land in the SMALLINT branch below).
                $this->mysql_field = str_replace("TINYINT", "FLOAT", $this->mysql_field);
            } elseif (abs($min) > 2147483647 OR abs($max) > 2147483647) {
                $this->mysql_field = str_replace("TINYINT", "BIGINT", $this->mysql_field);
            } elseif (abs($min) > 8388607 OR abs($max) > 8388607) {
                // A "refused" 99999999 or a date code 20260101 needs an INT
                // (signed MEDIUMINT ends at 8,388,607).
                $this->mysql_field = str_replace("TINYINT", "INT", $this->mysql_field);
            } elseif (abs($min) > 32767 OR abs($max) > 32767) {
                $this->mysql_field = str_replace("TINYINT", "MEDIUMINT", $this->mysql_field);
            } elseif (abs($min) > 126 OR abs($max) > 126) {
                // ($max, not $min twice: choices 1..3 + a 999 "don't know"
                // code got a TINYINT, and choosing 999 failed the whole page
                // write under STRICT_ALL_TABLES.)
                $this->mysql_field = str_replace("TINYINT", "SMALLINT", $this->mysql_field);
            }
        } else {
            $lengths = array_map("strlen", $choices);
            $maxlen = max($lengths);
            $this->mysql_field = 'VARCHAR (' . $maxlen . ') DEFAULT NULL';
        }
    }

    public function isStoredInResultsTable() {
        return $this->save_in_results_table;
    }

    public function getResultField() {
        // Only choices that are answer options decide the column type. A
        // pattern item's first choice is its mismatch message (#269), not an
        // option; typing its column from that "choice" made a text answer a
        // TINYINT and every save fail.
        if (!empty($this->choices) && $this->hasChoices) {
            $this->chooseResultFieldBasedOnChoices();
        }

        if ($this->mysql_field !== null) {
            return "`{$this->name}` {$this->mysql_field}";
        } else {
            return null;
        }
    }

    public function validate() {
        if (!$this->hasChoices && !$this->choicesOptional && ($this->choice_list !== null || count($this->choices))) {
            $this->val_errors[] = "'{$this->name}' You defined choices for this item, even though this type doesn't have choices.";
        } elseif ($this->hasChoices && !$this->choicesOptional && ($this->choice_list === null && count($this->choices) === 0) && $this->type !== "select_or_add_multiple") {
            $this->val_errors[] = "'{$this->name}' You forgot to define choices for this item.";
        } elseif ($this->hasChoices && count(array_unique($this->choices)) < count($this->choices)) {
            $dups = implode(", ", array_diff_assoc($this->choices, array_unique($this->choices)));
            $this->val_errors[] = "'{$this->name}' You defined duplicated choices (" . h($dups) . ") for this item.";
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]+$/', (string)$this->name)) {
            $this->val_errors[] = "'{$this->name}' The variable name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. It needs to start with a letter. You cannot use spaces, dots, or dashes.";
        }

        $reserved = $this->legacyHelperName ? self::RESULT_COLUMN_NAMES : self::RESERVED_NAMES;
        if (in_array((string)$this->name, $reserved, true) || self::isResultColumnName($this->name)) {
            $this->val_errors[] = "'{$this->name}' is a reserved variable name and cannot be used for an item. Reserved names are: " . implode(', ', $reserved) . ".";
        }

        if (trim($this->type) == '') {
            $this->val_errors[] = "{$this->name}: The type column must not be empty.";
        }

        $defined_classes = array_map('trim', explode(" ", (string)$this->class));
        $missing_classes = array_diff($defined_classes, $this->allowed_classes);
        if (count($missing_classes) > 0) {
            $this->val_warnings[] = "'{$this->name}' You used CSS classes that aren't part of the standard set (but maybe you defined them yourself): " . implode(", ", $missing_classes);
        }

        return array('val_errors' => $this->val_errors, 'val_warnings' => $this->val_warnings);
    }

    /**
     * validate() without its "this type doesn't have choices" check, for
     * items whose choices are not answer options but text (a pattern item's
     * mismatch message, a bot_check's relabelling). The choices are restored
     * afterwards for rendering.
     */
    protected function validateIgnoringChoices() {
        $choices = $this->choices;
        $choiceList = $this->choice_list;
        $this->choices = array();
        $this->choice_list = null;
        $result = self::validate();
        $this->choices = $choices;
        $this->choice_list = $choiceList;
        return $result;
    }

    /**
     * Reject a non-scalar reply on an item whose input is a single value.
     *
     * A client can post `name[]=x` against any item; the string functions
     * the scalar items then call on the reply (trim, strlen, filter_var,
     * strtotime, preg_match, htmlspecialchars) TypeError on PHP 8 and the
     * survey page turns into a 500. Sets the error and returns true when
     * the reply is unusable — the caller returns null. Null is left to the
     * caller (it is the "missed required" / optional-empty case).
     */
    public function rejectNonScalarReply($reply, $message = null) {
        if ($reply === null || is_scalar($reply) || $this->accepts_array_reply) {
            return false;
        }
        $this->reply = null;
        $this->error = $message !== null ? $message : __("This is not a valid answer.");
        return true;
    }

    public function validateInput($reply) {
        // Scalar by default: a select_one / timezone / hidden item that does
        // not override validateInput() would otherwise pass `name[]=x`
        // through to be stored as a JSON array in a single-value column.
        if ($this->rejectNonScalarReply($reply)) {
            return null;
        }
        $this->reply = $reply;

        if (!$this->optional && (($reply === null || $reply === false || $reply === array() || $reply === '') || (is_array($reply) && count($reply) === 1 && current($reply) === ''))) {
            // missed a required field
            $this->error = __("You missed entering some required information");
        } elseif ($this->optional && $reply == '') {
            $reply = null;
        }
        return $reply;
    }

    protected function setMoreOptions() {
        
    }

    protected function render_label() {
        $template = '<label class="%{class}" id="item%{id}-label" for="item%{id}">%{error} %{text} </label>';

        return Template::replace($template, array(
                    'class' => implode(' ', $this->classes_label),
                    'error' => $this->render_error_tip(),
                    'text' => $this->label_parsed,
                    'id' => $this->id,
        ));
    }

    protected function render_prepended() {
        $template = $this->prepend ? '<span class="input-group-addon"><i class="fa fa-fw %s"></i></span>' : '';
        return sprintf($template, $this->prepend);
    }

    protected function render_input() {
        if ($this->value_validated !== null) {
            $this->input_attributes['value'] = $this->value_validated;
        }

        return sprintf('<span><input %s /></span>', self::_parseAttributes($this->input_attributes));
    }

    protected function render_appended() {
        $template = $this->append ? '<span class="input-group-addon"><i class="%s"></i></span>' : '';
        return sprintf($template, $this->append);
    }

    protected function render_inner() {
        $template = $this->render_label();
        $template .= '
			<div class="%{classes_controls}" %{group_attrs}>
				<div class="controls-inner">
					%{input_group_open}
						%{prepended} %{input} %{appended}
					%{input_group_close}
				</div>
			</div>
		';

        $inputgroup = isset($this->prepend) || isset($this->append);
        // a11y group semantics for radio/checkbox sets (see $group_role).
        $group_attrs = $this->group_role
            ? sprintf('role="%s" aria-labelledby="item%d-label"', $this->group_role, (int) $this->id)
            : '';
        return Template::replace($template, array(
                    'classes_controls' => implode(' ', $this->classes_controls),
                    'group_attrs' => $group_attrs,
                    'input_group_open' => $inputgroup ? '<div class="input-group">' : '',
                    'input_group_close' => $inputgroup ? '</div>' : '',
                    'prepended' => $this->render_prepended(),
                    'input' => $this->render_input(),
                    'appended' => $this->render_appended(),
        ));
    }

    protected function render_item_view_input() {
        $template = '
			<span class="item-view-inputs">
				<input class="item_shown" type="hidden" name="_item_views[shown][%{id}]" />
				<input class="item_shown_relative" type="hidden" name="_item_views[shown_relative][%{id}]" />
				<input class="item_answered" type="hidden" name="_item_views[answered][%{id}]" />
				<input class="item_answered_relative" type="hidden" name="_item_views[answered_relative][%{id}]" />
			</span>
		';
        return Template::replace($template, array('id' => $this->id));
    }

    public function render() {
        if ($this->error) {
            $this->classes_wrapper[] = "has-error";
        }
        $this->classes_wrapper = array_unique($this->classes_wrapper);
        if ($this->data_showif) {
            $this->parent_attributes['data-showif'] = $this->js_showif;
        }
        if ($this->unseen) {
            $this->parent_attributes['data-fmr-unseen'] = '1';
        }
        $template = '
			<div class="%{classes_wrapper}" %{parent_attributes}>
				%{item_content}
				<div class="hidden_debug_message hidden item_name">
					<span class="badge hastooltip" title="%{title}">%{name}</span>
				</div>
			</div>
		';
        return Template::replace($template, array(
                    'classes_wrapper' => implode(' ', $this->classes_wrapper),
                    'parent_attributes' => self::_parseAttributes($this->parent_attributes),
                    'item_content' => $this->render_inner() . $this->render_item_view_input(),
                    'title' => h($this->js_showif),
                    'name' => h($this->name),
        ));
    }

    public function render_error_tip() {
        $format = $this->error ? '<span class="label label-danger hastooltip" title="%s"><i class="fa fa-exclamation-triangle"></i></span> ' : '';
        return sprintf($format, h($this->error));
    }

    protected function splitValues() {
        if (isset($this->value_validated)) {
            if (is_array($this->value_validated)) {
                $this->presetValues = $this->value_validated;
            } else {
                $this->presetValues = array_map("trim", explode(",", $this->value_validated));
            }
            unset($this->input_attributes['value']);
        } elseif (isset($this->input_attributes['value'])) {
            $this->presetValues[] = $this->input_attributes['value'];
        } else {
            $this->presetValues = array();
        }
    }

    public function hide() {
        if (!$this->hidden) {
            $this->classes_wrapper[] = "hidden";
            $this->data_showif = true;
            $this->input_attributes['disabled'] = true; ## so it isn't submitted or validated
            $this->hidden = true; ## so it isn't submitted or validated
        }
    }

    /**
     * Render the item visually hidden (CSS `.hidden` + disabled input) by DEFAULT
     * WITHOUT marking it server-side hidden (`$this->hidden` stays as-is, normally
     * null). form_v2 uses this for an NA showif (the dependency isn't answered yet,
     * or it references a server-only var): the item is hidden until the client
     * (Alpine x-showif) reveals it if the showif evaluates true — matching v1,
     * where an unevaluable showif defaults to hidden (survey.js `_hide = true`).
     * Keeping `$this->hidden` null leaves it in the unanswered set so the client
     * owns its visibility; the disabled input keeps it out of the POST until then.
     */
    public function hideByDefaultPendingClient() {
        if (!in_array('hidden', $this->classes_wrapper, true)) {
            $this->classes_wrapper[] = 'hidden';
        }
        $this->data_showif = true;
        $this->input_attributes['disabled'] = true;
    }

    public function alwaysInvalid() {
        $this->error = _('There were problems with openCPU.');
        if (!isset($this->input_attributes['class'])) {
            $this->input_attributes['class'] = '';
        }
        $this->input_attributes['class'] .= " always_invalid";
    }

    public function needsDynamicLabel($vars = [], $context = null) {
        return $this->label_parsed === null;
    }

    public function getShowIf() {
        if ($this->showif && strstr($this->showif, "//js_only") !== false) {
            return "NA";
        }

        if ($this->hidden !== null) {
            return false;
        }
        if (trim((string)$this->showif) != "") {
            return $this->showif;
        }
        return false;
    }

    public function needsDynamicValue() {
        $this->value = trim((string) $this->value);
        if (!(is_formr_truthy($this->value))) {
            $this->presetValue = null;
            return false;
        }
        if (is_numeric($this->value)) {
            $this->input_attributes['value'] = $this->value;
            return false;
        }

        return true;
    }

    /**
     * Is this item's value R (not empty, not a number)? Unlike
     * needsDynamicValue(), which prepares the item for rendering on the way
     * (trims the value, sets a numeric default, drops the preset of an empty
     * one), this only reads: for checks of items that are then rendered or
     * judged (formV2Unsupported).
     */
    public function hasDynamicValue() {
        $value = trim((string) $this->value);
        return is_formr_truthy($value) && !is_numeric($value);
    }

    public function evaluateDynamicValue(SurveyStudy $survey) {
        
    }

    public function getValue(SurveyStudy $survey = null) {
        if ($survey && $this->value === 'sticky') {
            $this->value = "tail(na.omit({$survey->name}\${$this->name}),1)";
        }
        return trim($this->value);
    }

    /**
     * Set the visibility of an item based on show-if results returned from opencpu
     * $showif_result Can be an array or an interger value returned by ocpu. If a non-empty array then $showif_result[0] can have the following values
     * - NULL if the variable in $showif is Not Avaliable,
     * - TRUE if it avalaible and true,
     * - FALSE if it avalaible and not true
     * - An empty array if a problem occured with opencpu
     *
     * @param array|int $showif_result
     * @return null
     */
    public function setVisibility($showif_result) {
        if (!$showif_result) {
            return true;
        }

        $result = true;
        if (is_array($showif_result) && array_key_exists(0, $showif_result)) {
            $result = $showif_result[0];
        } elseif ($showif_result === array()) {
            notify_user_error("You made a mistake, writing a showif <code class='r hljs'>" . $this->showif . "</code> that returns an element of length 0. The most common reason for this is to e.g. refer to data that does not exist. Valid return values for a showif are TRUE, FALSE and NULL/NA.", " There are programming problems in this survey.");
            $this->alwaysInvalid();
            $this->error = _('Incorrectly defined showif.');
        }

        if (!$result) {
            $this->hide();
            $this->probably_render = false;
        }
        // null means we can't determine clearly if item should be visible or not
        if ($result === null) {
            $this->probably_render = true;
        }
        return $result;
    }

    /**
     * Set the dynamic value computed on opencpu
     *
     * @param mixed $value Value
     * @return null
     */
    public function setDynamicValue($value) {
        if (!$value) {
            return;
        }

        $currentValue = $this->getValue();
        if ($value === array()) {
            notify_user_error("You made a mistake, writing a dynamic value <code class='r hljs'>" . h($currentValue) . "</code> that returns an element of length 0. The most common reason for this is to e.g. refer to data that does not exist, e.g. misspell an item. Valid values need to have a length of one.", " There are programming problems related to zero-length dynamic values in this survey.");
            $this->openCPU_errors[$value] = _('Incorrectly defined value (zero length).');
            $this->alwaysInvalid();
            $value = null;
        } elseif ($value === null) {
            notify_user_error("You made a mistake, writing a dynamic value <code class='r hljs'>" . h($currentValue) . "</code> that returns NA (missing). The most common reason for this is to e.g. refer to data that is not yet set, i.e. referring to questions that haven't been answered yet. To circumvent this, add a showif to your item, checking whether the item is answered yet using is.na(). Valid values need to have a length of one.", " There are programming problems related to null dynamic values in this survey.");
            $this->openCPU_errors[$value] = _('Incorrectly defined value (null).');
            $this->alwaysInvalid();
        } elseif (is_array($value) && array_key_exists(0, $value)) {
            $value = $value[0];
        }

        $this->input_attributes['value'] = $value;
    }

    public function getComputedValue() {
        if (isset($this->input_attributes['value'])) {
            return $this->input_attributes['value'];
        } else {
            return null;
        }
    }

    /**
     * Says if an item is visible or not. An item is visible if:
     * - It's hidden property is FALSE OR
     * - It's state cannot be determined at time of rendering
     *
     * @return boolean
     */
    public function isRendered() {
        return $this->requiresUserInput() && (!$this->hidden || $this->probably_render);
    }

    /**
     * Says if an item requires user input.
     *
     * @return boolean
     */
    public function requiresUserInput() {
        return !$this->no_user_input_required;
    }

    /**
     * Why this item cannot be part of a form_v2 study, or null when it can.
     *
     * form_v2 runs R only for what the participant's browser asks for (a
     * page's dynamic values and labels), never as the server's own
     * computation. So an item whose value only the server could compute — a
     * `calculate`, or any other item that takes no input but carries R in
     * `value` — has no place in v2. What the form needs is a `hidden` item
     * with the same `value` (resolved when its page is shown and submitted
     * with it); a score or group for feedback or later units belongs in the
     * R of those units. One rule, used by the upload into a v2 study, the
     * switch to v2 and the compatibility scan.
     *
     * @return string|null
     */
    public function formV2Unsupported() {
        if (!$this->requiresUserInput() && $this->hasDynamicValue()) {
            return "'{$this->name}': in v2, a {$this->type} item cannot have R code as its value. Clear the value, or use a hidden item with that value.";
        }
        return null;
    }

    /**
     * Why this item cannot run on the classic v1 engine, or null. `bot_check`
     * (its proof-of-work widget ships only in the form_v2 bundle — a
     * required one could never be answered) and `rank` (v1 would store the
     * author's order untouched) are form_v2 items. Refused at upload into a
     * v1 study; the upload form's Engine choice puts a new survey on v2.
     *
     * @return string|null
     */
    public function v1Unsupported() {
        if ($this->v2_only) {
            return "'{$this->name}': {$this->type} items only work in v2. Choose v2 when you upload the survey, or move the survey to v2 first.";
        }
        return null;
    }

    /**
     * Is an element hidden in DOM but rendered?
     *
     * @return boolean
     */
    public function isHiddenButRendered() {
        return $this->hidden && $this->probably_render;
    }

    public function setChoices($choices) {
        $this->choices = $choices;
    }

    public function getReply($reply) {
        return $reply;
    }

    /**
     * Render a stored result value for safe display inside the admin
     * results tables (show_results / show_itemdisplay), which echo cells
     * as raw HTML.
     *
     * Escaped by default: the overwhelming majority of item types store
     * free participant text (open-ended answers), and Server/Referrer/
     * Browser items store the User-Agent / Referer request headers, which
     * are fully attacker-controlled. Without escaping, a participant can
     * store `<img src=x onerror=…>` and run script in the admin's session
     * on admin_domain when results are viewed — crossing the admin/study
     * security boundary. Item types whose stored value is trusted,
     * self-generated HTML (the File/Audio/Video/Image embed markup)
     * override this to pass their markup through unescaped.
     */
    public function getEscapedResult($reply) {
        return h($reply);
    }

    /**
     * Returns a space-delimited string with items of the $options array. If a key
     * of $options array happens to be one of those listed in `Helper::$_minimizedAttributes`
     *
     * And its value is one of:
     *
     * - '1' (string)
     * - 1 (integer)
     * - true (boolean)
     * - 'true' (string)
     *
     * Then the value will be reset to be identical with key's name.
     * If the value is not one of these 3, the parameter is not output.
     *
     * 'escape' is a special option in that it controls the conversion of
     *  attributes to their html-entity encoded equivalents. Set to false to disable html-encoding.
     *
     * If value for any option key is set to `null` or `false`, that option will be excluded from output.
     *
     * @param array $options Array of options.
     * @param array $exclude Array of options to be excluded, the options here will not be part of the return.
     * @param string $insertBefore String to be inserted before options.
     * @param string $insertAfter String to be inserted after options.
     * @return string Composed attributes.
     */
    protected function _parseAttributes($options, $exclude = null, $insertBefore = ' ', $insertAfter = null) {
        if (!is_string($options)) {
            $options = (array) $options + array('escape' => true);

            if (!is_array($exclude)) {
                $exclude = array();
            }

            $exclude = array('escape' => true) + array_flip($exclude);
            $escape = $options['escape'];
            $attributes = array();

            foreach ($options as $key => $value) {
                if (!isset($exclude[$key]) && $value !== false && $value !== null) {
                    $attributes[] = $this->_formatAttribute($key, $value, $escape);
                }
            }
            $out = implode(' ', $attributes);
        } else {
            $out = $options;
        }
        return $out ? $insertBefore . $out . $insertAfter : '';
    }

    /**
     * Formats an individual attribute, and returns the string value of the composed attribute.
     * Works with minimized attributes that have the same value as their name such as 'disabled' and 'checked'
     *
     * @param string $key The name of the attribute to create
     * @param string $value The value of the attribute to create.
     * @param boolean $escape Define if the value must be escaped
     * @return string The composed attribute.
     */
    protected function _formatAttribute($key, $value, $escape = true) {
        if (is_array($value)) {
            $value = implode(' ', $value);
        }
        if (is_numeric($key)) {
            return sprintf($this->_minimizedAttributeFormat, $value, $value);
        }
        $truthy = array(1, '1', true, 'true', $key);
        $isMinimized = in_array($key, $this->_minimizedAttributes);
        if ($isMinimized && in_array($value, $truthy, true)) {
            return sprintf($this->_minimizedAttributeFormat, $key, $key);
        }
        if ($isMinimized) {
            return '';
        }
        return sprintf($this->_attributeFormat, $key, ($escape ? h($value) : $value));
    }

    protected function isSelectedOptionValue($expected = null, $actual = null) {
        if ($expected !== null && $actual !== null && $expected == $actual) {
            return true;
        }
        return false;
    }

}
