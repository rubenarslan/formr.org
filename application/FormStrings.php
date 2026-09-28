<?php

/**
 * FormStrings — participant-facing UI strings for the form_v2 engine chrome
 * (patch 096). Item labels/choices are authored in the study's language
 * already; this covers the engine's own strings around them (nav buttons,
 * save pill, offline banners, validation fallbacks, solo hints), which were
 * hardcoded English.
 *
 * The study's BCP 47 `language` picks a set; unknown languages fall back to
 * English key-by-key. The resolved table is injected into the page as
 * `window.fmrStrings` (templates/run/form_index.php) and read client-side by
 * webroot/assets/form/js/lib/i18n.js; FormRenderer uses get() server-side.
 *
 * Parameterized strings use named %tokens replaced by the caller (client
 * t(key, params) / server get(..., params)).
 *
 * Adding a language = adding one array below. A study can override the
 * navigation wording (studyOverrides(), patch 097 / #322).
 */
class FormStrings {

    private static $tables = array(
        'en' => array(
            'nav.previous' => 'Previous',
            'nav.next' => 'Next',
            'nav.submit' => 'Submit',
            'nav.continue' => 'Continue',
            'nav.back' => 'Back',
            'nav.ok' => 'OK',
            'save.saving' => 'Saving…',
            'save.saved' => 'Saved',
            'save.synced' => 'Synced',
            'save.offline' => 'Saved offline',
            'queue.syncing' => 'Syncing %n queued submission(s)…',
            'queue.offline_wait' => 'Offline — submissions will sync automatically when you reconnect.',
            'queue.rejected' => 'A queued submission was rejected. Please review the page.',
            'queue.all_sent' => 'All queued submissions have been sent.',
            'queue.queued_offline' => "You're offline. This submission is queued and will be sent when you reconnect.",
            'queue.queued_behind' => 'An earlier page is still waiting to be sent. This page is queued behind it and will be sent right after.',
            'queue.waiting_first' => 'An earlier page is still waiting to be sent. This page can be submitted once it has gone.',
            'queue.discard' => 'Discard and continue',
            'queue.discard_confirm' => 'Discard the answers that could not be sent (%n page(s))? They will be lost; the pages after them are sent.',
            'queue.needs_attention_stale' => 'A page from an earlier visit to this study could not be saved and can no longer be sent.',
            'completion.next_step' => 'Thanks — sending you to the next step…',
            'error.too_large' => "Submission too large to queue offline (%mb MB, limit %limit MB). Please retry when you're back online.",
            'error.send_failed' => 'Your submission could not be sent. Please check your connection and try again.',
            'error.queue_failed' => 'Your submission could not be queued locally. Please check your connection and try again.',
            'error.stale_session' => 'This form was continued elsewhere or a new entry has started. The page will reload to catch up.',
            'error.session_expired' => 'Your session has expired. The page will reload so you can continue.',
            'error.generic' => 'Sorry, something went wrong. Please try again.',
            'error.save_failed' => 'Sorry, the answer could not be saved. Please reload the page and try again.',
            'validation.required' => "We'll need this to continue.",
            'validation.pick_option' => 'Please pick an option to continue.',
            'progress.page_of' => 'Page %cur of %total',
            'progress.of' => '%cur of %total',
            'solo.hint_enter' => 'press Enter ↵',
            'solo.hint_textarea' => 'Ctrl + Enter to continue',
            'solo.hint_multi' => 'Choose all that apply, then OK',
            'validation.rank' => 'Please rank the options to continue.',
            'rank.hint' => 'Use the up and down arrow keys to move this option; Home and End move it to the top or bottom.',
            'rank.move_up' => 'Move up',
            'rank.move_down' => 'Move down',
            'rank.position' => '%label, position %pos of %total',
            'rank.moved' => '%label moved to position %pos of %total',
            'validation.bot_check' => 'Please verify that you are human to continue.',
            'validation.scale' => 'Please choose a point on the scale to continue.',
            'validation.location' => 'Please provide your location to continue.',
            'validation.file' => 'Please add a file to continue.',
            'validation.check_entry' => 'Please check this entry.',
            'solo.back_label' => 'Go to previous question',
            'solo.help_label' => 'Show contact and privacy links',
            'queue.needs_attention' => 'Your answers for page %page could not be saved: please fix the highlighted answers and submit the page again.',
            'queue.needs_attention_other_run' => 'A queued submission for another study on this device was rejected. Open that study to fix it.',
            'queue.go_to_page' => 'Go to page %page',
            'queue.retry' => 'Retry now',
            'queue.retrying' => 'Retrying…',
            'queue.blocked_session' => '%n queued submission(s) could not be sent because your session has expired. Open your personal study link to sign in again, then retry.',
            'queue.blocked' => '%n queued submission(s) could not be sent after several attempts. Your answers are kept on this device — please retry, or contact the study team if this persists.',
            'queue.retry_later' => "%n queued submission(s) could not be sent yet. We'll retry automatically.",
        ),
        'de' => array(
            'nav.previous' => 'Zurück',
            'nav.next' => 'Weiter',
            'nav.submit' => 'Absenden',
            'nav.continue' => 'Weiter',
            'nav.back' => 'Zurück',
            'nav.ok' => 'OK',
            'save.saving' => 'Speichern…',
            'save.saved' => 'Gespeichert',
            'save.synced' => 'Synchronisiert',
            'save.offline' => 'Offline gespeichert',
            'queue.syncing' => 'Übertrage %n zwischengespeicherte Antwort(en)…',
            'queue.offline_wait' => 'Offline — Antworten werden automatisch übertragen, sobald Sie wieder online sind.',
            'queue.rejected' => 'Eine zwischengespeicherte Antwort wurde abgelehnt. Bitte überprüfen Sie die Seite.',
            'queue.all_sent' => 'Alle zwischengespeicherten Antworten wurden übertragen.',
            'queue.queued_offline' => 'Sie sind offline. Diese Antwort wurde zwischengespeichert und wird übertragen, sobald Sie wieder online sind.',
            'queue.queued_behind' => 'Eine frühere Seite wartet noch auf die Übertragung. Diese Seite wurde dahinter eingereiht und wird direkt danach übertragen.',
            'queue.waiting_first' => 'Eine frühere Seite wartet noch auf die Übertragung. Diese Seite kann abgeschickt werden, sobald sie übertragen ist.',
            'queue.discard' => 'Verwerfen und fortfahren',
            'queue.discard_confirm' => 'Die Antworten verwerfen, die nicht übertragen werden konnten (%n Seite(n))? Sie gehen verloren; die Seiten danach werden übertragen.',
            'queue.needs_attention_stale' => 'Eine Seite von einem früheren Besuch dieser Studie konnte nicht gespeichert werden und kann nicht mehr übertragen werden.',
            'completion.next_step' => 'Danke — Sie werden zum nächsten Schritt weitergeleitet…',
            'error.too_large' => 'Antwort zu groß für die Offline-Warteschlange (%mb MB, Limit %limit MB). Bitte erneut versuchen, sobald Sie online sind.',
            'error.send_failed' => 'Ihre Antwort konnte nicht gesendet werden. Bitte prüfen Sie Ihre Verbindung und versuchen Sie es erneut.',
            'error.queue_failed' => 'Ihre Antwort konnte nicht lokal zwischengespeichert werden. Bitte prüfen Sie Ihre Verbindung und versuchen Sie es erneut.',
            'error.stale_session' => 'Dieses Formular wurde an anderer Stelle fortgesetzt oder ein neuer Eintrag wurde begonnen. Die Seite wird neu geladen.',
            'error.session_expired' => 'Ihre Sitzung ist abgelaufen. Die Seite wird neu geladen, damit Sie fortfahren können.',
            'error.generic' => 'Entschuldigung, etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.',
            'error.save_failed' => 'Entschuldigung, die Antwort konnte nicht gespeichert werden. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
            'validation.required' => 'Diese Angabe wird benötigt, um fortzufahren.',
            'validation.pick_option' => 'Bitte wählen Sie eine Option aus, um fortzufahren.',
            'progress.page_of' => 'Seite %cur von %total',
            'progress.of' => '%cur von %total',
            'solo.hint_enter' => 'Enter ↵ drücken',
            'solo.hint_textarea' => 'Strg + Enter zum Fortfahren',
            'solo.hint_multi' => 'Alles Zutreffende auswählen, dann OK',
            'validation.rank' => 'Bitte bringen Sie die Optionen in eine Reihenfolge, um fortzufahren.',
            'rank.hint' => 'Mit den Pfeiltasten nach oben und unten verschieben Sie diese Option; Pos1 und Ende verschieben sie ganz nach oben oder unten.',
            'rank.move_up' => 'Nach oben',
            'rank.move_down' => 'Nach unten',
            'rank.position' => '%label, Position %pos von %total',
            'rank.moved' => '%label auf Position %pos von %total verschoben',
            'validation.bot_check' => 'Bitte bestätigen Sie, dass Sie ein Mensch sind, um fortzufahren.',
            'validation.scale' => 'Bitte wählen Sie einen Punkt auf der Skala, um fortzufahren.',
            'validation.location' => 'Bitte geben Sie Ihren Standort an, um fortzufahren.',
            'validation.file' => 'Bitte fügen Sie eine Datei hinzu, um fortzufahren.',
            'validation.check_entry' => 'Bitte überprüfen Sie diese Angabe.',
            'solo.back_label' => 'Zur vorherigen Frage',
            'solo.help_label' => 'Kontakt- und Datenschutzlinks anzeigen',
            'queue.needs_attention' => 'Ihre Antworten auf Seite %page konnten nicht gespeichert werden: Bitte korrigieren Sie die markierten Antworten und senden Sie die Seite erneut ab.',
            'queue.needs_attention_other_run' => 'Eine zwischengespeicherte Antwort für eine andere Studie auf diesem Gerät wurde abgelehnt. Öffnen Sie diese Studie, um sie zu korrigieren.',
            'queue.go_to_page' => 'Zu Seite %page',
            'queue.retry' => 'Jetzt erneut versuchen',
            'queue.retrying' => 'Erneuter Versuch…',
            'queue.blocked_session' => '%n zwischengespeicherte Antwort(en) konnten nicht übertragen werden, da Ihre Sitzung abgelaufen ist. Öffnen Sie Ihren persönlichen Studienlink, um sich erneut anzumelden, und versuchen Sie es dann erneut.',
            'queue.blocked' => '%n zwischengespeicherte Antwort(en) konnten auch nach mehreren Versuchen nicht übertragen werden. Ihre Antworten bleiben auf diesem Gerät gespeichert – bitte versuchen Sie es erneut oder wenden Sie sich an das Studienteam.',
            'queue.retry_later' => '%n zwischengespeicherte Antwort(en) konnten noch nicht übertragen werden. Wir versuchen es automatisch erneut.',
        ),
    );

    /** RTL primary language subtags (dir="rtl" on the participant page). */
    private static $rtl = array('ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb');

    /**
     * Resolve the table for a BCP 47 tag: exact match, then primary subtag
     * ('de-AT' → 'de'), then English. Always returns EVERY key (missing
     * translations fall back to English key-by-key).
     */
    public static function table($language, array $overrides = array()) {
        $en = self::$tables['en'];
        $lang = strtolower(trim((string) $language));
        $chosen = array();
        if ($lang !== '' && $lang !== 'en') {
            $primary = strtok($lang, '-');
            $chosen = isset(self::$tables[$lang]) ? self::$tables[$lang]
                : (isset(self::$tables[$primary]) ? self::$tables[$primary] : array());
        }
        // Overrides replace known keys only, and only with a non-empty string.
        $overrides = array_filter(array_intersect_key($overrides, $en), function ($s) {
            return is_string($s) && $s !== '';
        });
        return array_merge($en, $chosen, $overrides);
    }

    /**
     * The strings a study replaces with its own wording (#322): the custom
     * Next label also stands in for the solo layout's page-boundary
     * "Continue"; the custom Submit label for the last page's button.
     *
     * @param SurveyStudy|null $study
     * @return array<string, string>
     */
    public static function studyOverrides($study) {
        $out = array();
        if ($study && !empty($study->next_button_label)) {
            $out['nav.next'] = (string) $study->next_button_label;
            $out['nav.continue'] = (string) $study->next_button_label;
        }
        if ($study && !empty($study->submit_button_label)) {
            $out['nav.submit'] = (string) $study->submit_button_label;
        }
        return $out;
    }

    /** One string, with %token replacement. */
    public static function get($language, $key, array $params = array()) {
        $table = self::table($language);
        $s = isset($table[$key]) ? $table[$key] : $key;
        foreach ($params as $token => $value) {
            $s = str_replace('%' . $token, (string) $value, $s);
        }
        return $s;
    }

    /** Text direction for the participant page. */
    public static function dir($language) {
        $primary = strtolower(strtok(trim((string) $language), '-'));
        return in_array($primary, self::$rtl, true) ? 'rtl' : 'ltr';
    }
}
