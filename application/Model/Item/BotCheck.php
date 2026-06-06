<?php

/**
 * `bot_check` — a local-only, GDPR-clean "are you human" challenge.
 *
 * A gating item in the mould of AddToHomeScreen_Item / PushNotification_Item:
 * a hidden input carries a value the participant can't type, JavaScript fills
 * it, and the server validates it in validateInput(). The value is an Altcha
 * (https://altcha.org, MIT, self-hosted) proof-of-work token, minted + verified
 * entirely on this server (BotCheckChallenge) with the memory-hard Argon2id
 * algorithm — no third-party captcha, no external request, no PII. This is
 * Altcha's free self-hosted PoW, NOT the Sentinel SaaS.
 *
 * The widget renders an <altcha-widget> whose challenge is fetched lazily from
 * /{run}/form-bot-challenge when it mounts (the JS layer fills in the
 * challengeurl from the form's data-run-url). Fetching on mount keeps the
 * challenge fresh in form_v2 (which renders every page into one document at
 * load), so it can't go stale before a participant reaches a later page.
 *
 * Spreadsheet usage:  type = `bot_check`  (optionally `bot_check 2` to set the
 * PoW prefix-byte difficulty, 1..3). Mark the item required (the default) so a
 * failed/missing token blocks the page submit.
 *
 * Customisable text — repurpose it as a consent / "I'm answering myself"
 * affirmation gate:
 *   - `label`   → the prompt/statement shown above the box (standard item label).
 *   - `choice1` → the box's clickable affirmation (default "Verify you are
 *                 human"), e.g. "I confirm I am completing this survey myself,
 *                 without automation."
 *   - `choice2` → the confirmation shown after clicking (default "Verified"),
 *                 e.g. "Thank you — confirmed."
 *   - `choice3` → the in-progress text (default "Verifying…").
 * Choices are optional here (unlike real choice items).
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

    /**
     * bot_check optionally carries choices to relabel the box / confirmation
     * (a consent affirmation). The base validator rejects any choices on a
     * non-choice type; suppress just that check while keeping the rest (name,
     * type, classes). Choices are restored afterwards for rendering.
     */
    public function validate() {
        $choices = $this->choices;
        $choiceList = $this->choice_list;
        $this->choices = array();
        $this->choice_list = null;
        $result = parent::validate();
        $this->choices = $choices;
        $this->choice_list = $choiceList;
        return $result;
    }

    protected function render_input() {
        // Author-customisable copy via choices (all optional): 1 = widget label,
        // 2 = verified-state text, 3 = verifying-state text. These map to Altcha's
        // i18n `strings` (label / verified / verifying). Falls back to Altcha's
        // own English defaults when a choice is empty.
        $choiceVals = array_values($this->choices);
        $strings = array();
        if (isset($choiceVals[0]) && $choiceVals[0] !== '') $strings['label'] = (string) $choiceVals[0];
        if (isset($choiceVals[1]) && $choiceVals[1] !== '') $strings['verified'] = (string) $choiceVals[1];
        if (isset($choiceVals[2]) && $choiceVals[2] !== '') $strings['verifying'] = (string) $choiceVals[2];

        // The widget creates its OWN hidden input named after the item and POSTs
        // the base64 payload there; we don't render one. It fetches the challenge
        // lazily from /{run}/form-bot-challenge — the JS layer (initBotCheck)
        // resolves the absolute challengeurl from the form's data-run-url at init
        // and registers the Argon2id worker. We emit a relative marker so the
        // widget stays inert until JS wires it (no eager network call).
        $cfg = array(
            'hideLogo' => true,
            'hideFooter' => true,
            'humanInteractionSignature' => false, // no pointer/scroll telemetry collection
        );
        if (!empty($strings)) {
            $cfg['strings'] = $strings;
        }

        $name = htmlspecialchars($this->name, ENT_QUOTES);
        $config = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        // Per-item difficulty (prefix bytes 1..3) from `bot_check N`. The endpoint
        // clamps it and the signature pins it, so a tampered query can't weaken
        // the gate below the configured minimum.
        $diff = $this->chosenDifficulty();
        $diffAttr = ($diff !== null) ? sprintf(' data-difficulty="%d"', (int) $diff) : '';

        // data-fmr-botcheck flags the wrapper for initBotCheck(); data-challenge-path
        // is the run-relative endpoint the JS turns into an absolute challengeurl.
        return sprintf(
            '<div class="fmr-botcheck" data-fmr-botcheck data-challenge-path="form-bot-challenge"%s>'
            . '<altcha-widget name="%s" auto="off" configuration=\'%s\'></altcha-widget>'
            . '</div>',
            $diffAttr,
            $name,
            $config
        );
    }

    public function validateInput($reply) {
        // Store a compact marker, not the (large) base64 token. The token only
        // needs to verify here; persisting it adds no value and would bloat the
        // result column.
        if (BotCheckChallenge::verify($reply)) {
            $this->reply = 'verified';
            return 'verified';
        }
        $this->reply = $reply;
        // The save loop (UnitSession::updateSurveyStudyRecord) detects a failed
        // item by `$item->error` being set — NOT by the return value. Without
        // this the invalid/empty token is silently accepted. Setting it both
        // blocks the submit and surfaces the message inline.
        $this->error = __('Please verify that you are human to continue.');
        return null;
    }
}
