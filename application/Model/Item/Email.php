<?php

/**
 * email is a special HTML5 type, validation is polyfilled in browsers that lack it
 */
class Email_Item extends Text_Item {

    public $type = 'email';
    public $input_attributes = array('type' => 'email', 'maxlength' => 255);
    public $mysql_field = 'VARCHAR(255) DEFAULT NULL';
    protected $prepend = 'fa-envelope';

    // Browsers accept `name@host` as an email address; PHP's
    // FILTER_VALIDATE_EMAIL (validateInput below) does not. Without a dot in
    // the domain the form passed the browser's check and then failed on the
    // server (#327), so require one in the browser too. Inside a character
    // class only `@` and whitespace are excluded: safe under the `v` flag
    // browsers compile patterns with.
    const BROWSER_PATTERN = '[^@\\s]+@[^@\\s]+\\.[^@\\s]+';

    protected function setMoreOptions() {
        parent::setMoreOptions();
        if (!isset($this->input_attributes['pattern'])) {
            $this->input_attributes['pattern'] = self::BROWSER_PATTERN;
        }
    }

    protected function patternMessage() {
        $message = parent::patternMessage();
        return $message !== null ? $message : __('Please enter a valid email address, like name@example.org.');
    }

    public function validateInput($reply) {
        if ($this->rejectNonScalarReply($reply, __('The email address is not valid'))) {
            return null;
        }
        if ($this->optional && trim($reply) == '') {
            return parent::validateInput($reply);
        } else {
            $reply_valid = filter_var($reply, FILTER_VALIDATE_EMAIL);
            if (!$reply_valid) {
                $this->error = __('The email address %s is not valid', h($reply));
            }
        }

        return $reply_valid;
    }

}
