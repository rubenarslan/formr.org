<?php

/**
 * FormStrings — participant-facing UI strings for the form_v2 engine chrome
 * (patch 077). Item labels/choices are authored in the study's language
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
 * Adding a language = adding one array below. Per-study custom overrides are
 * a possible follow-up (see the form_v2 punch list).
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
        ),
    );

    /** RTL primary language subtags (dir="rtl" on the participant page). */
    private static $rtl = array('ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb');

    /**
     * Resolve the table for a BCP 47 tag: exact match, then primary subtag
     * ('de-AT' → 'de'), then English. Always returns EVERY key (missing
     * translations fall back to English key-by-key).
     */
    public static function table($language) {
        $en = self::$tables['en'];
        $lang = strtolower(trim((string) $language));
        if ($lang === '' || $lang === 'en') {
            return $en;
        }
        $primary = strtok($lang, '-');
        $chosen = isset(self::$tables[$lang]) ? self::$tables[$lang]
            : (isset(self::$tables[$primary]) ? self::$tables[$primary] : array());
        return array_merge($en, $chosen);
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
