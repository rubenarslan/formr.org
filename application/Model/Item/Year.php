<?php

class Year_Item extends Datetime_Item {

    public $type = 'year';
    // `type="year"` isn't a real HTML type — browsers fall back to text, which
    // shows the alpha keyboard on mobile. inputmode=numeric restores the digit
    // pad without changing the (text) field's validation behaviour.
    public $input_attributes = array('type' => 'year', 'inputmode' => 'numeric');
    public $mysql_field = 'YEAR DEFAULT NULL';
    protected $html5_date_format = 'Y';
    protected $prepend = 'fa-calendar-o';

    protected function setMoreOptions() {
        $this->classes_input[] = 'form-control';

        // A year is a number, not a date: strtotime('1985') is 1985 today, but
        // strtotime('2015') is today at 20:15, so Datetime_Item's parsing turned
        // every year from 1900-1959 and 2000-2059 into the current year and
        // compared years against today's timestamps. A four-digit bound is
        // taken as is; anything else ("now", "-18 years") still goes through
        // strtotime, which is what it means there.
        if (isset($this->type_options) && trim($this->type_options) != "") {
            $this->type_options_array = explode(",", $this->type_options, 3);
            $min = self::parseYearBound(reset($this->type_options_array));
            if ($min !== null) {
                $this->input_attributes['min'] = $min;
            }
            $max = self::parseYearBound(next($this->type_options_array));
            if ($max !== null) {
                $this->input_attributes['max'] = $max;
            }
        }

        // `type="year"` falls back to a plain text input, which carries no
        // native constraint — so v2's Constraint-Validation client (blur +
        // submit) had nothing to check and never flagged a bad year. Give it a
        // 4-digit pattern + length + range so it validates instantly, the same
        // way number/email do. Only fill defaults the admin didn't set.
        $this->input_attributes['pattern'] = '\\d{4}';
        $this->input_attributes['maxlength'] = 4;
        if (!isset($this->input_attributes['min'])) {
            $this->input_attributes['min'] = 1901; // MySQL YEAR lower bound
        }
        if (!isset($this->input_attributes['max'])) {
            $this->input_attributes['max'] = 2155; // MySQL YEAR upper bound
        }
    }

    /** A type_options bound as a year (int), or null when it isn't one. */
    protected static function parseYearBound($bound) {
        $bound = trim((string) $bound);
        if ($bound === '') {
            return null;
        }
        if (preg_match('/^\d{4}$/', $bound)) {
            return (int) $bound;
        }
        $time = strtotime($bound);
        return $time ? (int) date('Y', $time) : null;
    }

    public function validateInput($reply) {
        if ($this->rejectNonScalarReply($reply, _('You did not enter a valid date.'))) {
            return null;
        }
        $year = trim((string) $reply);
        if (!($this->optional && $year === '')) {
            if (!preg_match('/^\d{4}$/', $year)) {
                $this->error = _('You did not enter a valid date.');
            } elseif (isset($this->input_attributes['min']) && (int) $year < (int) $this->input_attributes['min']) {
                $this->error = __("The minimum is %d", $this->input_attributes['min']);
            } elseif (isset($this->input_attributes['max']) && (int) $year > (int) $this->input_attributes['max']) {
                $this->error = __("The maximum is %d", $this->input_attributes['max']);
            }
        }
        // Item's required/optional handling, not Datetime_Item's strtotime checks.
        return Item::validateInput($year === '' ? $reply : $year);
    }

    public function getReply($reply) {
        $year = trim((string) $reply);
        return preg_match('/^\d{4}$/', $year) ? $year : null;
    }

}
