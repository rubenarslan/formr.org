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
 * /{run}/form-bot-challenge?item=<name> when it mounts (the JS layer fills in
 * the challengeurl from the form's data-run-url; the server looks the item up
 * in the current study to set the difficulty). Fetching on mount keeps the
 * challenge fresh in form_v2 (which renders every page into one document at
 * load), so it can't go stale before a participant reaches a later page.
 *
 * Spreadsheet usage:  type = `bot_check`  (optionally `bot_check 2` to set the
 * PoW prefix-byte difficulty, 1..3). Leave the item required (the default) so a
 * failed/missing token blocks the page submit; an `optional` bot_check may be
 * skipped (stored NULL), but a token that IS submitted is always verified.
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

    // Validating spends the one-shot token; the widget ships only in the
    // form_v2 bundle.
    protected $validation_has_side_effects = true;
    protected $v2_only = true;

    // Its stored marker ('verified') is not a token the widget can take.
    public function rendersStoredAnswer() {
        return false;
    }

    /** @var string|null the burn of the token this reply verified (validateInput) */
    private $burnId = null;

    /**
     * A check passed once in this unit session stands: a resend of its page
     * (the retry of a submit whose reply was lost or that failed after
     * storing, a queued copy) is not asked to verify again. Its one-shot
     * token was spent the first time, and the widget still shows
     * "verified", so it would be refused forever.
     */
    public function settledAnswer(UnitSession $unitSession) {
        return $unitSession->storedAnswer($this) === 'verified' ? 'verified' : null;
    }

    /**
     * The page this token was verified for was not stored (another item
     * failed, or the write did): give the token back, so the resubmit, its
     * widget still showing "verified", is not refused as a replay. A token
     * still yields at most one stored answer.
     */
    public function finishSideEffects($stored) {
        if (!$stored && $this->burnId !== null) {
            RegistrationGuard::unburn($this->burnId);
        }
        $this->burnId = null;
    }

    public $type = 'bot_check';
    public $no_user_input_required = false;
    public $hasChoices = false;
    public $mysql_field = 'VARCHAR(20) DEFAULT NULL';

    public function getResultField() {
        return "`{$this->name}` {$this->mysql_field}";
    }

    /**
     * The `N` of `bot_check N` (PoW prefix bytes), or null when the sheet left
     * it unset. This is the study-authored difficulty; BotCheckChallenge reads
     * it at mint AND verify time, so it can't be lowered from the client.
     */
    public function chosenDifficulty() {
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
        return $this->validateIgnoringChoices();
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
        // data-difficulty is INFORMATIONAL only (shows the `bot_check N` the sheet
        // set). The client never sends it: the mint endpoint takes the item NAME
        // (data-item / the widget's name) and derives the difficulty from this
        // item's row in the study, and verify() re-derives it the same way — so
        // nothing in the markup or the request can weaken the gate.
        $diff = $this->chosenDifficulty();
        $diffAttr = ($diff !== null) ? sprintf(' data-difficulty="%d"', (int) $diff) : '';

        // data-fmr-botcheck flags the wrapper for initBotCheck(); data-challenge-path
        // is the run-relative endpoint the JS turns into an absolute challengeurl.
        return sprintf(
            '<div class="fmr-botcheck" data-fmr-botcheck data-challenge-path="form-bot-challenge" data-item="%s"%s>'
            . '<altcha-widget name="%s" auto="off" configuration=\'%s\'></altcha-widget>'
            . '</div>',
            $name,
            $diffAttr,
            $name,
            $config
        );
    }

    public function validateInput($reply) {
        // `optional` means the box may be left alone, as for every other item
        // (Item::validateInput nulls an empty optional reply). The client-side
        // gate (validation/feedback.js) already skips a non-`required` group,
        // so without this the participant was blocked here with no way past.
        // Only an ABSENT token is skippable: a token that was submitted still
        // goes through the fail-closed checks below — optional says "may be
        // skipped", not "may be forged".
        if ($this->optional && ($reply === null || $reply === false || $reply === array() || (is_string($reply) && trim($reply) === ''))) {
            $this->reply = null;
            return null;
        }

        // Fail closed on the wire shape before anything else. A missing field, an
        // empty string, or a non-scalar (`bot_check[]=x`) is not a token and must
        // never reach verify() as something that might be "accepted".
        if (!is_string($reply) || trim($reply) === '') {
            return $this->refuse(__('Please verify that you are human to continue.'));
        }

        // Distinguish "this server cannot run the check" from "this participant
        // failed it". Both block the submit — verify() fails closed — but an
        // unreadable crypto key is an admin problem and the participant can't do
        // anything about it, so don't tell them to click the box again.
        if (!BotCheckChallenge::available()) {
            return $this->refuse(__('Human verification is temporarily unavailable. Please contact the study administrator.'));
        }

        // Store a compact marker, not the (large) base64 token. The token only
        // needs to verify here; persisting it adds no value and would bloat the
        // result column. $this carries the difficulty floor (`bot_check N`).
        if (BotCheckChallenge::verify($reply, $this, $this->burnId)) {
            $this->reply = 'verified';
            return 'verified';
        }
        return $this->refuse(__('Please verify that you are human to continue.'));
    }

    /**
     * What is stored is this item's verdict, never the posted token: the
     * display row is written from the posted value (UnitSession::
     * writeSurveyStudyRecord), which for a bot_check is the whole base64
     * token — stored in survey_items_display.answer it reached long reads
     * and made a resend of the page look unverified. 'verified' once
     * validateInput accepted it (or when handed the stored marker), else
     * nothing.
     */
    public function getReply($reply) {
        if ($this->reply === 'verified' || $reply === 'verified') {
            return 'verified';
        }
        return null;
    }

    /**
     * Block the submit with $message.
     *
     * The save loop (UnitSession::updateSurveyStudyRecord) detects a failed item
     * by `$item->error` being set — NOT by the return value. Without it an
     * invalid/empty token is silently accepted. The reply is deliberately NOT
     * kept: it is either empty or an unverified base64 blob far longer than the
     * VARCHAR(20) result column, and there is nothing to learn from storing it.
     */
    private function refuse($message) {
        $this->reply = null;
        $this->error = $message;
        return null;
    }
}
