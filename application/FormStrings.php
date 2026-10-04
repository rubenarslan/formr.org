<?php

/**
 * FormStrings — participant-facing UI strings for the form_v2 engine chrome
 * (patch 114). Item labels/choices are authored in the study's language
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
 * navigation wording (studyOverrides(), patch 115 / #322).
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
            'validation.two_weekdays' => 'Please choose no more than two weekdays.',
            'expiry.title' => 'Page expired',
            'expiry.body' => 'This page has become outdated. Please refresh.',
            'expiry.reload' => 'Reload',
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
            // PWA items (add_to_home_screen, push_notification, request_cookie,
            // request_phone). Strings with markup are inserted as HTML by the
            // bundle; the rest are escaped.
            'pwa.install.using_installed' => 'You are using the installed app.',
            'pwa.install.installed' => 'Installed',
            'pwa.install.failed' => 'Installation didn\'t complete. You can try again or add to home screen manually from your browser menu.',
            'pwa.install.add_button' => 'Add to Home Screen',
            'pwa.install.dismissed' => 'Install dismissed. You can add the app to your home screen at any time.',
            'pwa.install.required' => 'Please add this study to your home screen to continue.',
            'pwa.install.no_support' => 'Your browser doesn\'t support adding this study to the home screen. Try Safari (iOS), Chrome on Android, or Edge on desktop.',
            'pwa.install.preparing' => 'Preparing installation…',
            'pwa.processing' => 'Processing…',
            'pwa.install.not_prompted' => 'Your browser hasn\'t offered an install prompt. Try a supported browser (Chrome on Android, Edge on desktop, Safari on iOS).',
            'pwa.push.test_title' => 'Test notification',
            'pwa.push.test_body' => 'This is a test notification. If you can see this, notifications are working!',
            'pwa.push.help_ios' => '<ol><li>Open <strong>Settings</strong> → <strong>Notifications</strong>.</li><li>Find Safari (or your browser) and enable <strong>Allow Notifications</strong>.</li><li>Make sure <strong>Focus</strong> mode isn\'t blocking notifications.</li><li>For home-screen apps, check <strong>Settings → Screen Time → Content & Privacy Restrictions</strong>.</li><li>Reload this page after changing settings.</li></ol>',
            'pwa.push.help_android' => '<ol><li>Open <strong>Settings → Apps</strong>, find your browser.</li><li>Tap <strong>Notifications</strong> and ensure they\'re <strong>Allowed</strong>.</li><li>Check that <strong>Do Not Disturb</strong> isn\'t blocking notifications.</li><li>Some manufacturers have battery-optimization settings that suppress notifications — disable them for your browser.</li><li>Try installing this study to your home screen for better notification support.</li><li>Reload this page after changing settings.</li></ol>',
            'pwa.push.help_mac' => '<ol><li>Open <strong>System Settings → Notifications</strong>.</li><li>Find your browser and ensure <strong>Allow Notifications</strong> is on.</li><li>Check that <strong>Do Not Disturb</strong> / <strong>Focus</strong> is off.</li><li>Reload this page after changing settings.</li></ol>',
            'pwa.push.help_windows' => '<ol><li>Open <strong>Settings → System → Notifications</strong>.</li><li>Ensure your browser has notifications turned on.</li><li>Check that <strong>Focus assist</strong> isn\'t blocking them.</li><li>Reload this page after changing settings.</li></ol>',
            'pwa.push.help_other' => 'Check your operating system\'s notification settings to make sure your browser is allowed to send notifications.',
            'pwa.push.help_hide' => 'Hide troubleshooting tips',
            'pwa.push.test_button' => 'Test notification',
            'pwa.push.disable_button' => 'Disable notifications',
            'pwa.push.help_show' => 'Show troubleshooting tips',
            'pwa.push.unsubscribed' => 'You have unsubscribed from notifications. Click the button to enable them again.',
            'pwa.push.enable_button' => 'Enable Notifications',
            'pwa.push.enabled_button' => 'Notifications Enabled',
            'pwa.push.required' => 'Please complete this required step before continuing.',
            'pwa.push.ios_install_required' => 'Please add this study to your home screen first, then open it from the home screen icon to enable notifications.',
            'pwa.push.ios_install_first' => '<strong>Add to Home Screen first.</strong> On iOS, push notifications only work after you install this study to your home screen and open it from there.',
            'pwa.push.unsupported' => 'Sorry, your browser does not support push notifications.',
            'pwa.push.unsupported_validity' => 'This browser does not support push notifications. Please use a supported browser to continue.',
            'pwa.push.not_configured' => 'Push notifications are not configured for this study.',
            'pwa.push.not_configured_validity' => 'Push notifications are not configured for this study. Contact the study administrator.',
            'pwa.push.ios_version' => 'Sorry, push notifications require iOS 16.4 or later.',
            'pwa.push.ios_version_validity' => 'Push notifications require iOS 16.4 or later. Please update your device to continue.',
            'pwa.push.no_sw' => 'Service worker not registered. Please reload the page and try again.',
            'pwa.push.no_sw_validity' => 'Service worker is not registered. Reload the page and try again.',
            'pwa.push.enabled' => 'Push notifications are enabled.',
            'pwa.push.blocked_validity' => 'Notifications are blocked. Enable them in your browser/system settings, then tap the button again.',
            'pwa.push.declined_retry' => 'You have declined push notifications. You can enable them in your browser settings, then click the button below.',
            'pwa.push.blocked_retry_button' => 'Notifications blocked — click after enabling in browser settings',
            'pwa.push.click_to_enable' => 'Click the button to enable push notifications.',
            'pwa.push.already_enabled' => 'Push notifications are already enabled.',
            'pwa.push.android_note' => '<strong>Note for Android users:</strong> on some devices, you may need to restart your browser or add this study to your home screen for notifications to work properly.',
            'pwa.push.enabled_success' => 'Push notifications enabled successfully!',
            'pwa.push.enabled_not_saved' => 'Push notifications enabled locally — couldn\'t save the subscription on the server. Reload to retry.',
            'pwa.push.test_sent' => 'A test notification was sent. If you didn\'t see it, your system settings might be blocking it.',
            'pwa.push.declined_validity' => 'You declined push notifications. Enable them in your browser/system settings, then reload to try again.',
            'pwa.push.blocked_button' => 'Notifications blocked',
            'pwa.push.declined' => 'You declined push notifications. You can enable them in your browser settings later.',
            'pwa.push.config_error' => 'Server configuration error. Please contact the study administrator.',
            'pwa.push.config_error_button' => 'Configuration error',
            'pwa.push.failed_validity' => 'We couldn\'t enable push notifications. If you\'re on iOS, make sure you\'ve added this study to your home screen and opened it from there.',
            'pwa.push.failed_ios_hint' => 'On iOS, push notifications only work after you\'ve added this study to your home screen and opened it from there.',
            'pwa.push.failed_title' => 'Couldn\'t enable push notifications.',
            'pwa.push.failed_retry_later' => 'Please try again later or contact the study administrator.',
            'pwa.push.error_button' => 'Error — try again',
            'pwa.push.unexpected_validity' => 'We hit an unexpected error enabling push notifications. Please reload and try again.',
            'pwa.push.failed_reload' => 'Please reload and try again, or contact the study administrator if this keeps happening.',
            'pwa.cookie.enabled' => 'Functional cookies enabled. You can continue.',
            'pwa.cookie.enabled_button' => 'Enabled',
            'pwa.cookie.required' => 'Please enable functional cookies to continue.',
            'pwa.phone.copy' => 'Copy link to phone',
            'pwa.phone.copied' => 'Copied',
            'pwa.phone.on_mobile' => 'You are already on a mobile device. You can continue.',
            'pwa.phone.required' => 'Please continue this study on your phone — scan the code above.',
            'pwa.phone.scan_required' => 'Scan this QR code with your phone to continue the study there.',
            'pwa.phone.scan_optional' => 'Scan this QR code to continue on your phone, or continue here.',
            'geo.manual_placeholder' => 'You can also type your location here',
            'geo.locating' => 'Locating…',
            'geo.denied' => 'Location access was blocked — please type your location instead.',
            'geo.failed' => "Couldn't get your location automatically — please type it instead.",
            'recorder.unsupported_audio' => 'Your browser does not support audio recording in any required format.',
            'recorder.unsupported_video' => 'Your browser does not support video recording in any required format.',
            'recorder.denied' => 'Permission to record was denied or the device is unavailable.',
            'recorder.start_failed' => 'Recording could not be started on this device.',
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
            'validation.two_weekdays' => 'Bitte wählen Sie höchstens zwei Wochentage.',
            'expiry.title' => 'Seite abgelaufen',
            'expiry.body' => 'Diese Seite ist veraltet. Bitte laden Sie sie neu.',
            'expiry.reload' => 'Neu laden',
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
            'pwa.install.using_installed' => 'Sie verwenden die installierte App.',
            'pwa.install.installed' => 'Installiert',
            'pwa.install.failed' => 'Die Installation wurde nicht abgeschlossen. Sie können es erneut versuchen oder die Studie manuell über das Menü Ihres Browsers zum Home-Bildschirm hinzufügen.',
            'pwa.install.add_button' => 'Zum Home-Bildschirm',
            'pwa.install.dismissed' => 'Installation abgebrochen. Sie können die App jederzeit zu Ihrem Home-Bildschirm hinzufügen.',
            'pwa.install.required' => 'Bitte fügen Sie diese Studie zu Ihrem Home-Bildschirm hinzu, um fortzufahren.',
            'pwa.install.no_support' => 'Ihr Browser unterstützt das Hinzufügen dieser Studie zum Home-Bildschirm nicht. Versuchen Sie es mit Safari (iOS), Chrome unter Android oder Edge auf dem Computer.',
            'pwa.install.preparing' => 'Installation wird vorbereitet…',
            'pwa.processing' => 'Wird bearbeitet…',
            'pwa.install.not_prompted' => 'Ihr Browser hat keine Installation angeboten. Versuchen Sie es mit einem unterstützten Browser (Chrome unter Android, Edge auf dem Computer, Safari unter iOS).',
            'pwa.push.test_title' => 'Test-Benachrichtigung',
            'pwa.push.test_body' => 'Dies ist eine Test-Benachrichtigung. Wenn Sie sie sehen, funktionieren Benachrichtigungen!',
            'pwa.push.help_ios' => '<ol><li>Öffnen Sie <strong>Einstellungen</strong> → <strong>Mitteilungen</strong>.</li><li>Wählen Sie Safari (oder Ihren Browser) und aktivieren Sie <strong>Mitteilungen erlauben</strong>.</li><li>Stellen Sie sicher, dass kein <strong>Fokus</strong> Mitteilungen blockiert.</li><li>Prüfen Sie bei Apps auf dem Home-Bildschirm <strong>Einstellungen → Bildschirmzeit → Beschränkungen</strong>.</li><li>Laden Sie diese Seite neu, nachdem Sie die Einstellungen geändert haben.</li></ol>',
            'pwa.push.help_android' => '<ol><li>Öffnen Sie <strong>Einstellungen → Apps</strong> und wählen Sie Ihren Browser.</li><li>Tippen Sie auf <strong>Benachrichtigungen</strong> und stellen Sie sicher, dass sie <strong>erlaubt</strong> sind.</li><li>Prüfen Sie, dass <strong>Bitte nicht stören</strong> keine Benachrichtigungen blockiert.</li><li>Bei manchen Herstellern unterdrücken Energiespar- bzw. Akku-Optimierungseinstellungen Benachrichtigungen — deaktivieren Sie diese für Ihren Browser.</li><li>Fügen Sie diese Studie zu Ihrem Home-Bildschirm hinzu, damit Benachrichtigungen zuverlässiger funktionieren.</li><li>Laden Sie diese Seite neu, nachdem Sie die Einstellungen geändert haben.</li></ol>',
            'pwa.push.help_mac' => '<ol><li>Öffnen Sie <strong>Systemeinstellungen → Mitteilungen</strong>.</li><li>Wählen Sie Ihren Browser und stellen Sie sicher, dass <strong>Mitteilungen erlauben</strong> aktiviert ist.</li><li>Prüfen Sie, dass <strong>Nicht stören</strong> / <strong>Fokus</strong> ausgeschaltet ist.</li><li>Laden Sie diese Seite neu, nachdem Sie die Einstellungen geändert haben.</li></ol>',
            'pwa.push.help_windows' => '<ol><li>Öffnen Sie <strong>Einstellungen → System → Benachrichtigungen</strong>.</li><li>Stellen Sie sicher, dass Benachrichtigungen für Ihren Browser aktiviert sind.</li><li>Prüfen Sie, dass <strong>Nicht stören</strong> bzw. der <strong>Benachrichtigungsassistent</strong> sie nicht blockiert.</li><li>Laden Sie diese Seite neu, nachdem Sie die Einstellungen geändert haben.</li></ol>',
            'pwa.push.help_other' => 'Prüfen Sie in den Benachrichtigungseinstellungen Ihres Betriebssystems, ob Ihr Browser Benachrichtigungen senden darf.',
            'pwa.push.help_hide' => 'Tipps zur Fehlerbehebung ausblenden',
            'pwa.push.test_button' => 'Test-Benachrichtigung',
            'pwa.push.disable_button' => 'Benachrichtigungen deaktivieren',
            'pwa.push.help_show' => 'Tipps zur Fehlerbehebung anzeigen',
            'pwa.push.unsubscribed' => 'Sie haben die Benachrichtigungen abbestellt. Klicken Sie auf die Schaltfläche, um sie wieder zu aktivieren.',
            'pwa.push.enable_button' => 'Benachrichtigungen aktivieren',
            'pwa.push.enabled_button' => 'Benachrichtigungen aktiviert',
            'pwa.push.required' => 'Bitte schließen Sie diesen erforderlichen Schritt ab, bevor Sie fortfahren.',
            'pwa.push.ios_install_required' => 'Bitte fügen Sie diese Studie zuerst zu Ihrem Home-Bildschirm hinzu und öffnen Sie sie dann über das Symbol auf dem Home-Bildschirm, um Benachrichtigungen zu aktivieren.',
            'pwa.push.ios_install_first' => '<strong>Bitte zuerst zum Home-Bildschirm hinzufügen.</strong> Unter iOS funktionieren Push-Benachrichtigungen nur, wenn Sie diese Studie zu Ihrem Home-Bildschirm hinzufügen und sie von dort aus öffnen.',
            'pwa.push.unsupported' => 'Leider unterstützt Ihr Browser keine Push-Benachrichtigungen.',
            'pwa.push.unsupported_validity' => 'Dieser Browser unterstützt keine Push-Benachrichtigungen. Bitte verwenden Sie einen unterstützten Browser, um fortzufahren.',
            'pwa.push.not_configured' => 'Push-Benachrichtigungen sind für diese Studie nicht eingerichtet.',
            'pwa.push.not_configured_validity' => 'Push-Benachrichtigungen sind für diese Studie nicht eingerichtet. Bitte wenden Sie sich an die Studienleitung.',
            'pwa.push.ios_version' => 'Leider erfordern Push-Benachrichtigungen iOS 16.4 oder neuer.',
            'pwa.push.ios_version_validity' => 'Push-Benachrichtigungen erfordern iOS 16.4 oder neuer. Bitte aktualisieren Sie Ihr Gerät, um fortzufahren.',
            'pwa.push.no_sw' => 'Der Service Worker ist nicht registriert. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
            'pwa.push.no_sw_validity' => 'Der Service Worker ist nicht registriert. Laden Sie die Seite neu und versuchen Sie es erneut.',
            'pwa.push.enabled' => 'Push-Benachrichtigungen sind aktiviert.',
            'pwa.push.blocked_validity' => 'Benachrichtigungen sind blockiert. Aktivieren Sie sie in den Browser- bzw. Systemeinstellungen und tippen Sie dann erneut auf die Schaltfläche.',
            'pwa.push.declined_retry' => 'Sie haben Push-Benachrichtigungen abgelehnt. Sie können sie in Ihren Browsereinstellungen aktivieren und dann auf die Schaltfläche unten klicken.',
            'pwa.push.blocked_retry_button' => 'Benachrichtigungen blockiert — nach Aktivierung in den Browsereinstellungen hier klicken',
            'pwa.push.click_to_enable' => 'Klicken Sie auf die Schaltfläche, um Push-Benachrichtigungen zu aktivieren.',
            'pwa.push.already_enabled' => 'Push-Benachrichtigungen sind bereits aktiviert.',
            'pwa.push.android_note' => '<strong>Hinweis für Android-Nutzer:</strong> Auf manchen Geräten müssen Sie Ihren Browser neu starten oder diese Studie zu Ihrem Home-Bildschirm hinzufügen, damit Benachrichtigungen zuverlässig funktionieren.',
            'pwa.push.enabled_success' => 'Push-Benachrichtigungen wurden erfolgreich aktiviert!',
            'pwa.push.enabled_not_saved' => 'Push-Benachrichtigungen wurden auf diesem Gerät aktiviert, das Abonnement konnte aber nicht auf dem Server gespeichert werden. Laden Sie die Seite neu, um es erneut zu versuchen.',
            'pwa.push.test_sent' => 'Eine Test-Benachrichtigung wurde gesendet. Falls Sie sie nicht gesehen haben, blockieren möglicherweise Ihre Systemeinstellungen Benachrichtigungen.',
            'pwa.push.declined_validity' => 'Sie haben Push-Benachrichtigungen abgelehnt. Aktivieren Sie sie in den Browser- bzw. Systemeinstellungen und laden Sie die Seite dann neu, um es erneut zu versuchen.',
            'pwa.push.blocked_button' => 'Benachrichtigungen blockiert',
            'pwa.push.declined' => 'Sie haben Push-Benachrichtigungen abgelehnt. Sie können sie später in Ihren Browsereinstellungen aktivieren.',
            'pwa.push.config_error' => 'Konfigurationsfehler auf dem Server. Bitte wenden Sie sich an die Studienleitung.',
            'pwa.push.config_error_button' => 'Konfigurationsfehler',
            'pwa.push.failed_validity' => 'Push-Benachrichtigungen konnten nicht aktiviert werden. Wenn Sie iOS verwenden, stellen Sie sicher, dass Sie diese Studie zu Ihrem Home-Bildschirm hinzugefügt und von dort aus geöffnet haben.',
            'pwa.push.failed_ios_hint' => 'Unter iOS funktionieren Push-Benachrichtigungen nur, wenn Sie diese Studie zu Ihrem Home-Bildschirm hinzugefügt und von dort aus geöffnet haben.',
            'pwa.push.failed_title' => 'Push-Benachrichtigungen konnten nicht aktiviert werden.',
            'pwa.push.failed_retry_later' => 'Bitte versuchen Sie es später erneut oder wenden Sie sich an die Studienleitung.',
            'pwa.push.error_button' => 'Fehler — erneut versuchen',
            'pwa.push.unexpected_validity' => 'Beim Aktivieren der Push-Benachrichtigungen ist ein unerwarteter Fehler aufgetreten. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
            'pwa.push.failed_reload' => 'Bitte laden Sie die Seite neu und versuchen Sie es erneut, oder wenden Sie sich an die Studienleitung, falls das Problem weiterhin besteht.',
            'pwa.cookie.enabled' => 'Funktionale Cookies sind aktiviert. Sie können fortfahren.',
            'pwa.cookie.enabled_button' => 'Aktiviert',
            'pwa.cookie.required' => 'Bitte aktivieren Sie funktionale Cookies, um fortzufahren.',
            'pwa.phone.copy' => 'Link fürs Handy kopieren',
            'pwa.phone.copied' => 'Kopiert',
            'pwa.phone.on_mobile' => 'Sie verwenden bereits ein Mobilgerät. Sie können fortfahren.',
            'pwa.phone.required' => 'Bitte setzen Sie diese Studie auf Ihrem Handy fort — scannen Sie dazu den Code oben.',
            'pwa.phone.scan_required' => 'Scannen Sie diesen QR-Code mit Ihrem Handy, um die Studie dort fortzusetzen.',
            'pwa.phone.scan_optional' => 'Scannen Sie diesen QR-Code, um auf Ihrem Handy fortzufahren, oder fahren Sie hier fort.',
            'geo.manual_placeholder' => 'Sie können Ihren Standort auch hier eingeben',
            'geo.locating' => 'Standort wird ermittelt…',
            'geo.denied' => 'Der Zugriff auf den Standort wurde blockiert — bitte geben Sie Ihren Standort stattdessen ein.',
            'geo.failed' => 'Ihr Standort konnte nicht automatisch ermittelt werden — bitte geben Sie ihn stattdessen ein.',
            'recorder.unsupported_audio' => 'Ihr Browser unterstützt keine Audioaufnahme in einem der benötigten Formate.',
            'recorder.unsupported_video' => 'Ihr Browser unterstützt keine Videoaufnahme in einem der benötigten Formate.',
            'recorder.denied' => 'Die Berechtigung zur Aufnahme wurde verweigert oder das Gerät ist nicht verfügbar.',
            'recorder.start_failed' => 'Die Aufnahme konnte auf diesem Gerät nicht gestartet werden.',
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
        // Set means not NULL and not blank: "0" is a label
        // (SurveyStudy::normalizeButtonLabel keeps it).
        if ($study && (string) $study->next_button_label !== '') {
            $out['nav.next'] = (string) $study->next_button_label;
            $out['nav.continue'] = (string) $study->next_button_label;
        }
        if ($study && (string) $study->submit_button_label !== '') {
            $out['nav.submit'] = (string) $study->submit_button_label;
        }
        return $out;
    }

    /** A study's participant language (patch 114); 'en' when unset. */
    public static function studyLanguage(SurveyStudy $study) {
        return !empty($study->language) ? (string) $study->language : 'en';
    }

    /** The string table a study's participants see: its language plus its own wording. */
    public static function forStudy(SurveyStudy $study) {
        return self::table(self::studyLanguage($study), self::studyOverrides($study));
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
