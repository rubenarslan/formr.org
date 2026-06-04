<?php

/**
 * `bot_check` — a local-only, GDPR-clean "are you human" challenge.
 *
 * A gating item in the mould of AddToHomeScreen_Item / PushNotification_Item:
 * a hidden input carries a value the participant can't type, JavaScript fills
 * it, and the server validates it in validateInput(). Here the value is a
 * proof-of-work token minted + verified entirely on this server
 * (BotCheckChallenge) — no third-party captcha, no external request, no PII.
 *
 * Spreadsheet usage:  type = `bot_check`  (optionally `bot_check 18` to set the
 * PoW difficulty in leading-zero bits). Mark the item required (the default) so
 * a failed/missing token blocks the page submit.
 */
class BotCheck_Item extends Item {

    public $type = 'bot_check';
    public $no_user_input_required = false;
    public $hasChoices = false;
    public $mysql_field = 'VARCHAR(20) DEFAULT NULL';

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    protected function chosenDifficulty() {
        $opt = trim((string) $this->type_options);
        return ($opt !== '' && is_numeric($opt)) ? (int) $opt : null;
    }

    protected function render_input() {
        $challenge = BotCheckChallenge::mint($this->chosenDifficulty());

        $label = 'Verify you are human';
        if (!empty($this->choices)) {
            $label = reset($this->choices);
        }

        // Challenge data for the widget. When the server can't sign (no crypto
        // key) we still render a plain confirm box; verify() then fails open.
        $data = '';
        if ($challenge !== null) {
            $data = sprintf(
                ' data-iat="%d" data-salt="%s" data-diff="%d" data-sig="%s"',
                (int) $challenge['iat'],
                htmlspecialchars($challenge['salt'], ENT_QUOTES),
                (int) $challenge['diff'],
                htmlspecialchars($challenge['sig'], ENT_QUOTES)
            );
        }

        $hidden = sprintf(
            '<input type="hidden" name="%s" id="%s" value="" />',
            htmlspecialchars($this->name, ENT_QUOTES),
            htmlspecialchars($this->name, ENT_QUOTES)
        );

        // Turnstile-style control: a div (role=checkbox) the widget drives
        // through unverified → verifying → verified. The real gate is the signed
        // PoW token written into the hidden input, not the click itself.
        $template = '<div class="fmr-botcheck" data-fmr-botcheck%s>'
            . '%s'
            . '<div class="fmr-botcheck-box" role="checkbox" aria-checked="false" tabindex="0">'
            . '<span class="fmr-botcheck-indicator" aria-hidden="true"></span>'
            . '<span class="fmr-botcheck-label">%s</span>'
            . '</div>'
            . '<div class="fmr-botcheck-status" aria-live="polite"></div>'
            . '</div>';

        return sprintf($template, $data, $hidden, htmlspecialchars($label, ENT_QUOTES));
    }

    public function validateInput($reply) {
        $this->reply = $reply;
        if (BotCheckChallenge::verify($reply)) {
            return 'passed';
        }
        // The save loop (UnitSession::updateSurveyStudyRecord) detects a failed
        // item by `$item->error` being set — NOT by the return value. Without
        // this the invalid/empty token is silently accepted. Setting it both
        // blocks the submit and surfaces the message inline.
        $this->error = __('Please verify that you are human to continue.');
        return null;
    }
}
