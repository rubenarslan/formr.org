<?php
// Write an xlsx for an upload test. Reads {"<sheet title>": [[row], ...], ...}
// as JSON on stdin; writes the workbook to the path in argv[1]. Host PHP with
// the repo's composer deps (like bin/gen_pwa_items_xlsx.php).
//
//   echo '{"survey":[["type","name","label"],["text","q1","Q1"]]}' | php tests/e2e/setup/make-xlsx.php /tmp/x.xlsx
require __DIR__ . '/../../../vendor/autoload.php';

$sheets = json_decode(stream_get_contents(STDIN), true);
if (!is_array($sheets) || empty($argv[1])) {
    fwrite(STDERR, "usage: echo '<json sheets>' | php make-xlsx.php <out.xlsx>\n");
    exit(1);
}
$book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$book->removeSheetByIndex(0);
foreach ($sheets as $title => $rows) {
    $ws = $book->createSheet();
    $ws->setTitle($title);
    if ($rows) {
        $ws->fromArray($rows, null, 'A1');
    }
}
\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($argv[1]);
