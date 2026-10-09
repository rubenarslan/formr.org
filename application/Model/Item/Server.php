<?php

/**
 * `server` — stores one value the participant's browser sent with the
 * request (a request header), or one the server knows about the request.
 * `server VAR` names it; without an option it is the User-Agent
 * (`browser` is that, by its own name).
 *
 * Only the variables in VARIABLES can be stored. An item that takes no
 * input is stored without the participant doing anything: on the classic
 * engine when its page is reached, on form_v2 when the form is first
 * rendered.
 */
class Server_Item extends Item {

    /**
     * What a `server` item may store, as `$_SERVER` names.
     *
     * The client hints (Sec-CH-UA…) are what a Chromium browser says about
     * itself beside the User-Agent: its brands and versions, whether it is a
     * mobile browser, its operating system. A User-Agent rewritten on the
     * way leaves them as they were.
     *
     * The signature headers are HTTP Message Signatures (RFC 9421), with
     * which an AI agent that identifies itself signs its requests:
     * Signature-Agent names who signed (ChatGPT agent sends
     * "https://chatgpt.com"), Signature-Input what was signed and with which
     * key, Signature the signature itself.
     */
    const VARIABLES = array(
        'HTTP_USER_AGENT',
        'HTTP_ACCEPT',
        'HTTP_ACCEPT_CHARSET',
        'HTTP_ACCEPT_ENCODING',
        'HTTP_ACCEPT_LANGUAGE',
        'HTTP_CONNECTION',
        'HTTP_HOST',
        'QUERY_STRING',
        'REQUEST_TIME',
        'REQUEST_TIME_FLOAT',
        'HTTP_SEC_CH_UA',
        'HTTP_SEC_CH_UA_MOBILE',
        'HTTP_SEC_CH_UA_PLATFORM',
        'HTTP_SIGNATURE_AGENT',
        'HTTP_SIGNATURE_INPUT',
        'HTTP_SIGNATURE',
    );

    public $type = 'server';
    public $input_attributes = array('type' => 'hidden');
    public $mysql_field = 'TEXT DEFAULT NULL';
    public $no_user_input_required = true;
    public $optional = 1;
    private $get_var = 'HTTP_USER_AGENT';

    protected function setMoreOptions() {
        if (isset($this->type_options_array) && is_array($this->type_options_array)) {
            // An empty option cell is no option: the User-Agent.
            if (count($this->type_options_array) == 1 && trim((string) current($this->type_options_array)) !== '') {
                $this->get_var = trim(current($this->type_options_array));
            }
        }
        // Read only what may be stored: validate() refuses the item at
        // upload, but an item is also made from rows already in the table.
        $this->input_attributes['value'] = in_array($this->get_var, self::VARIABLES, true) ? array_val($_SERVER, $this->get_var) : null;
    }

    public function getReply($reply) {
        return $this->input_attributes['value'];
    }

    public function validate() {
        if (!in_array($this->get_var, self::VARIABLES, true)) {
            $this->val_errors[] = __('The server variable %s with the value %s cannot be saved', $this->name, $this->get_var);
        }
        // Always the item's own rules too (its name, above all): a valid
        // variable used to return early, in a shape the caller read as "no
        // errors", so a JSON import could give a server item any name.
        return parent::validate();
    }

    public function render() {
        return $this->render_input();
    }

}
