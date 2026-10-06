#!/usr/bin/php
<?php
/**
 * Regenerates the committed example survey for the data_quality and
 * agent_probe items: documentation/example_surveys/data_quality.xlsx.
 *
 * Three pages, no R and no showif, so the same sheet runs on both engines:
 *
 *   1  consent     a note with the consent paragraph and an "I agree" button.
 *                  No data_quality item: nothing is recorded on this page.
 *   2  questions   three ratings and an open text question, the `browser`
 *                  item (User-Agent header), an agent_probe and the page's
 *                  data_quality item, just above the submit button.
 *   3  questions   the plausible-deniability question (`rrt_ai`), a number,
 *                  an optional text, an agent_probe and a data_quality item.
 *
 * The consent paragraph and the question's wording come from
 * documentation/data_quality/texts.php, which the admin documentation page
 * (/admin/documentation/data-quality) shows as well. Re-run this after
 * changing them or $rows below, and commit the regenerated .xlsx.
 *
 * Usage — on the HOST, from the repo root, with composer deps installed (see
 * bin/gen_pwa_items_xlsx.php for why not inside the container):
 *
 *   php bin/gen_data_quality_xlsx.php
 *
 * Exits 0 on success, 1 if the destination is missing or the write failed.
 */
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$texts = require __DIR__ . '/../documentation/data_quality/texts.php';
$out = __DIR__ . '/../documentation/example_surveys/data_quality.xlsx';
if (!is_dir(dirname($out)) || !is_writable(dirname($out)) || (file_exists($out) && !is_writable($out))) {
    fwrite(STDERR, "gen_data_quality_xlsx: cannot write $out — run this on the host, not inside formr_app\n");
    exit(1);
}

$header = ['type', 'name', 'label', 'optional', 'class', 'showif', 'value', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5'];
$scale = ['1: not at all', '2', '3', '4', '5: very much'];
$row = function ($type, $name, $label = '', $optional = '', array $choices = []) {
    return array_merge([$type, $name, $label, $optional, '', '', ''], array_pad($choices, 5, ''));
};

$rows = [
    // Page 1: consent. Nothing is recorded here.
    $row('note', 'consent_text', "## Before you start\n\nThis short example study asks about your last weekend.\n\n" . $texts['consent']),
    $row('mc_button', 'consent', 'Do you agree to take part?', '', ['Yes, I agree']),
    $row('submit', 'to_questions', 'Continue'),

    // Page 2. The agent_probe's label and choice1 are empty: its default
    // question and note are used. The data_quality item comes last.
    $row('browser', 'dq_srv_ua', '', '*'),
    $row('mc_button', 'weekend_rested', 'Last weekend I felt rested.', '', $scale),
    $row('mc_button', 'weekend_social', 'Last weekend I spent time with other people.', '', $scale),
    $row('mc_button', 'weekend_outdoors', 'Last weekend I was outdoors.', '', $scale),
    $row('textarea', 'weekend_text', 'What did you do last weekend? Two or three sentences are enough.'),
    $row('agent_probe', 'dq_agent_p1', '', '*'),
    $row('data_quality', 'dq_p1', '', '*'),
    $row('submit', 'to_last_page', 'Continue'),

    // Page 3.
    $row('mc_button', 'rrt_ai', $texts['rrt_question'], '', ['Yes', 'No']),
    $row('number', 'screen_hours', 'About how many hours a day do you spend in front of a screen?'),
    $row('textarea', 'purpose_text', 'What do you think this study is about?', '*'),
    $row('agent_probe', 'dq_agent_p2', '', '*'),
    $row('data_quality', 'dq_p2', '', '*'),
    $row('submit', 'finish', 'Finish'),
];

$ss = new Spreadsheet();
$survey = $ss->getActiveSheet();
$survey->setTitle('survey');
$survey->fromArray($header, null, 'A1');
$survey->fromArray($rows, null, 'A2');
$choices = $ss->createSheet();
$choices->setTitle('choices');
$choices->fromArray(['list_name', 'name', 'label'], null, 'A1');

try {
    (new Xlsx($ss))->save($out);
} catch (Throwable $e) {
    fwrite(STDERR, "gen_data_quality_xlsx: could not write $out: " . $e->getMessage() . "\n");
    exit(1);
}
clearstatcache(true, $out);
if (!is_file($out) || filesize($out) === 0) {
    fwrite(STDERR, "gen_data_quality_xlsx: failed to write $out\n");
    exit(1);
}
echo "wrote $out (" . filesize($out) . " bytes)\n";
