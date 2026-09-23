<?php

/**
 * `rank` — drag-and-drop rank order (form_v2 only).
 *
 * Modelled on Qualtrics' "Rank order → drag and drop": the choices render as a
 * vertical list the participant reorders by dragging (mouse or touch), with the
 * per-row ▲/▼ buttons, or by keyboard (focus a row, Arrow Up/Down, Home/End).
 * The stored value is the choice *keys* in their final order joined by ", "
 * (first listed = rank 1), e.g. `3, 1, 2` — the same shape mc_multiple stores
 * several keys in, so `strsplit(rank, ", ")` in R recovers the ranking.
 *
 * Spreadsheet usage: `type` = `rank <choice_list> [random] [touch]` plus the
 * usual choice_list / choice columns (or the `type_options` column).
 *   - `random` (alias `shuffle`): each render starts from a shuffled order, so
 *     an untouched submit doesn't silently store the authored order.
 *   - `touch` (alias `require_change`): the item counts as answered only once
 *     the participant has interacted with it — dragged, tapped a row, or used
 *     the buttons/keyboard. Without it, submitting keeps the displayed order as
 *     the answer, like Qualtrics' default.
 *
 * Mechanics: every row carries a hidden `<name>[]` input holding its choice
 * key, so DOM order *is* the submitted order — nothing to serialise. In touch
 * mode those inputs start empty and the bundle fills them on first
 * interaction, so an untouched required rank fails both the generic
 * client-side "answered" gate (validation/feedback.js) and validateInput().
 *
 * v2-only: the widget needs webroot/assets/form/js/items/rank.js, which only
 * the form_v2 bundle ships. A v1 Survey unit renders just the hidden inputs.
 */
class Rank_Item extends Item {

    public $type = 'rank';
    public $input_attributes = array('type' => 'hidden');
    public $mysql_field = 'VARCHAR (255) DEFAULT NULL';
    protected $hasChoices = true;
    protected $group_role = 'group';
    // Posts `<name>[]` (one hidden input per row), so opt out of the scalar
    // shape check UnitSession runs before validateInput() — without this every
    // rank answer was refused as "not a valid answer".
    protected $accepts_array_reply = true;

    /** Keywords the `type_options` may carry (see class doc). */
    public static $optionKeywords = array('random', 'shuffle', 'touch', 'require_change');

    /**
     * Is this token a rank option keyword? SpreadsheetReader consults this so
     * `rank random` isn't parsed as "choice list named random".
     */
    public static function isOptionKeyword($token) {
        return in_array(strtolower(trim((string) $token)), self::$optionKeywords, true);
    }

    protected function options() {
        $tokens = preg_split('/\s+/', strtolower(trim((string) $this->type_options)), -1, PREG_SPLIT_NO_EMPTY);
        return array_intersect($tokens, self::$optionKeywords);
    }

    public function startsRandom() {
        return (bool) array_intersect($this->options(), array('random', 'shuffle'));
    }

    public function requiresTouch() {
        return (bool) array_intersect($this->options(), array('touch', 'require_change'));
    }

    protected function setMoreOptions() {
        $this->input_attributes['name'] = $this->name . '[]';
    }

    /** The answer is a ", "-joined list of keys, so size the column for all of them. */
    protected function chooseResultFieldBasedOnChoices() {
        $max = implode(", ", array_keys($this->choices));
        $this->mysql_field = 'VARCHAR (' . max(1, strlen($max)) . ') DEFAULT NULL';
    }

    protected function choiceKeys() {
        return array_map('strval', array_keys($this->choices));
    }

    /** Non-empty string entries of a posted array (or a stored ", " string). */
    protected function normalizeReply($reply) {
        if (is_string($reply)) {
            $reply = explode(',', $reply);
        }
        if (!is_array($reply)) {
            return array();
        }
        $out = array();
        foreach ($reply as $v) {
            if (is_array($v)) continue;
            $v = trim((string) $v);
            if ($v !== '') $out[] = $v;
        }
        return $out;
    }

    public function validateInput($reply) {
        $this->reply = $reply;
        $ranked = $this->normalizeReply($reply);
        if (empty($ranked)) {
            if ($this->optional) {
                return null;
            }
            $this->error = __("Please rank the options to continue.");
            return $reply;
        }
        $keys = $this->choiceKeys();
        $bad = array_diff($ranked, $keys);
        if (!empty($bad)) {
            $this->error = __("You chose an option '%s' that is not permitted.", h(implode("', '", $bad)));
            return $reply;
        }
        if (count($ranked) !== count($keys) || count(array_unique($ranked)) !== count($ranked)) {
            $this->error = __("Please rank every option exactly once.");
            return $reply;
        }
        return $ranked;
    }

    public function getReply($reply) {
        if (is_array($reply)) {
            $reply = implode(", ", $this->normalizeReply($reply));
            if ($reply === '') $reply = null;
        }
        return $reply;
    }

    /**
     * Rendered order: a preset (the participant's previous answer, or the
     * `value` column) wins, then `random`, then the authored order. A preset
     * that only names some keys goes first; the rest follow in authored order.
     */
    protected function startOrder(&$hasPreset) {
        $keys = $this->choiceKeys();
        $this->splitValues();
        $preset = array_values(array_unique(array_intersect($this->normalizeReply($this->presetValues), $keys)));
        $hasPreset = !empty($preset);
        if ($hasPreset) {
            return array_merge($preset, array_values(array_diff($keys, $preset)));
        }
        if ($this->startsRandom()) {
            shuffle($keys);
        }
        return $keys;
    }

    protected function render_label() {
        // No `for=`: there's no single input. The .controls wrapper (role=group)
        // points back here via aria-labelledby (see Item::render_inner).
        $template = '<label class="%{class}" id="item%{id}-label">%{error} %{text} </label>';
        return Template::replace($template, array(
            'class' => implode(' ', $this->classes_label),
            'error' => $this->render_error_tip(),
            'text' => $this->label_parsed,
            'id' => $this->id,
        ));
    }

    protected function render_input() {
        $hasPreset = false;
        $order = $this->startOrder($hasPreset);
        $touch = $this->requiresTouch();
        // A preset means the participant already ranked (or the sheet did):
        // that counts as touched, so a re-render after a validation error on
        // some other item doesn't demand a second interaction.
        $filled = !$touch || $hasPreset;
        $name = h($this->name . '[]');
        $total = count($order);

        // The ▲/▼ buttons are pointer affordances (tabindex=-1); keyboard users
        // move the focused row with the arrow keys instead, so the tab order
        // stays one stop per row. rank.js labels the buttons (i18n) at init.
        $rows = '';
        foreach ($order as $i => $key) {
            $label = isset($this->choices[$key]) ? $this->choices[$key] : $key;
            $rows .= sprintf(
                '<li class="fmr-rank-item" data-value="%1$s" tabindex="0" aria-describedby="item%2$d-rank-hint">'
                . '<span class="fmr-rank-pos" aria-hidden="true">%3$d</span>'
                . '<span class="fmr-rank-handle" aria-hidden="true"></span>'
                . '<span class="fmr-rank-label">%4$s</span>'
                . '<span class="fmr-rank-btns">'
                . '<button type="button" class="fmr-rank-up" tabindex="-1"%6$s></button>'
                . '<button type="button" class="fmr-rank-down" tabindex="-1"%7$s></button>'
                . '</span>'
                . '<input type="hidden" name="%5$s" value="%8$s" />'
                . '</li>',
                h($key), (int) $this->id, $i + 1, $label, $name,
                $i === 0 ? ' disabled' : '', $i === $total - 1 ? ' disabled' : '',
                $filled ? h($key) : ''
            );
        }

        return sprintf(
            '<div class="fmr-rank%1$s" data-fmr-rank%2$s>'
            . '<ol class="fmr-rank-list">%3$s</ol>'
            . '<span id="item%4$d-rank-hint" class="visually-hidden"></span>'
            . '<span class="fmr-rank-status visually-hidden" aria-live="polite"></span>'
            . '</div>',
            $filled ? ' fmr-rank-touched' : '',
            $touch ? ' data-rank-touch="1"' : '',
            $rows,
            (int) $this->id
        );
    }
}
