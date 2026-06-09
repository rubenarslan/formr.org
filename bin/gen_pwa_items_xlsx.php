<?php
// One-shot generator for two PWA-items test surveys (solo + non-solo): the four
// PWA items in the order request_phone → add_to_home_screen → request_cookie →
// push_notification, plus a submit. Run inside formr_app:
//   php bin/gen_pwa_items_xlsx.php
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
    $out = __DIR__ . '/../documentation/example_surveys/' . $base . '.xlsx';
    (new Xlsx($ss))->save($out);
    echo "wrote $out\n";
}
