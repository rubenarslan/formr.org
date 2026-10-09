#!/usr/bin/php
<?php
/**
 * Regenerates the committed PWA-items example surveys.
 *
 * Writes two byte-identical XLSform sheets into
 * `documentation/example_surveys/`:
 *
 *   pwa_items_default.xlsx   upload, leave the study on layout='default'
 *   pwa_items_solo.xlsx      upload, set the study to layout='solo'
 *
 * They are identical on purpose: `layout` is a per-study setting
 * (`survey_studies.layout`), not a sheet column, so exercising both form_v2
 * layouts means uploading the same items twice and flipping the setting on
 * one of them. Two files keep the pair discoverable in the admin survey list
 * (and in `documentation/example_surveys/`) without an out-of-band note about
 * which study got which layout.
 *
 * Contents: the four PWA items in the order a participant meets them —
 * request_phone -> add_to_home_screen -> request_cookie -> push_notification
 * — plus a trailing `submit`. All four are marked optional so the sheet can be
 * walked end-to-end in a desktop browser, where the native install/permission
 * prompts do not fire.
 *
 * Idempotent: overwrites the two files in place and prints what it wrote.
 * Re-run it after editing $header / $rows below; commit the regenerated
 * .xlsx alongside the change. Nothing else reads this script — it is a
 * fixture maintenance tool, not part of any runtime path.
 *
 * Usage — run it on the HOST, from the repo root, with composer deps installed:
 *
 *   php bin/gen_pwa_items_xlsx.php
 *
 * Not inside the `formr_app` container: on a bind-mounted dev checkout the
 * repo files belong to the host user and the container's www-data cannot
 * overwrite them ("Could not open file ... for writing"). These are files you
 * are about to `git add` anyway, so the host is the right place.
 *
 * Exits 0 on success, 1 if the destination is missing or a write failed.
 */
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$outDir = __DIR__ . '/../documentation/example_surveys';
if (!is_dir($outDir) || !is_writable($outDir)) {
    fwrite(STDERR, "gen_pwa_items_xlsx: $outDir is missing or not writable\n");
    exit(1);
}

$header = ['scale', 'class', 'type', 'name', 'showif', 'optional', 'label',
    'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'value'];

$rows = [
    // scale, class, type,                name,            showif, optional, label
    ['', '', 'request_phone',       'continue_phone', '', 1, 'Continue on your phone'],
    ['', '', 'add_to_home_screen',  'install_app',    '', 1, 'Add this study to your home screen'],
    ['', '', 'request_cookie',      'allow_cookies',  '', 1, 'Enable functional cookies'],
    ['', '', 'push_notification',   'push_perms',     '', 1, 'Enable push notifications'],
    ['', '', 'submit',              'done',           '', '', 'Finish'],
];

foreach (['pwa_items_solo', 'pwa_items_default'] as $base) {
    $ss = new Spreadsheet();
    $survey = $ss->getActiveSheet();
    $survey->setTitle('survey');
    $survey->fromArray($header, null, 'A1');
    $survey->fromArray($rows, null, 'A2');
    $choices = $ss->createSheet();
    $choices->setTitle('choices');
    $choices->fromArray(['list_name', 'name', 'label'], null, 'A1');

    $out = $outDir . '/' . $base . '.xlsx';
    if (file_exists($out) && !is_writable($out)) {
        fwrite(STDERR, "gen_pwa_items_xlsx: $out exists but is not writable by "
            . "this user — run this on the host, not inside formr_app\n");
        exit(1);
    }

    // Report the failure ourselves: PhpSpreadsheet throws, and with
    // display_errors off (the container default) an uncaught throw exits 255
    // with no message at all — which reads as "it worked" in a script.
    try {
        (new Xlsx($ss))->save($out);
    } catch (Throwable $e) {
        fwrite(STDERR, "gen_pwa_items_xlsx: could not write $out: "
            . $e->getMessage() . "\n");
        exit(1);
    }

    // Verify rather than trusting save() — a silent no-write here ships a
    // stale fixture that only fails much later, in a browser.
    clearstatcache(true, $out);
    if (!is_file($out) || filesize($out) === 0) {
        fwrite(STDERR, "gen_pwa_items_xlsx: failed to write $out\n");
        exit(1);
    }
    echo "wrote $out (" . filesize($out) . " bytes)\n";
}
