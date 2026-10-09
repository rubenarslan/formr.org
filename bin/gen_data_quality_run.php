#!/usr/bin/php
<?php
/**
 * Regenerates the committed example RUN for the data-quality items:
 *
 *   documentation/run_components/Data_Quality.json      classic Survey units
 *   documentation/run_components/Data_Quality_v2.json   the same as Form units (v2)
 *
 * Import either one into a run (run editor → Import). It is a whole study,
 * modelled on the prototype's "weekend study":
 *
 *   10  consent      a survey of its own: the consent text and the question.
 *                    No data_quality item, so nothing is recorded here — and
 *                    because it is its own survey, the `browser` and `server`
 *                    rows of the next one are stored only after consent, on
 *                    v2 as well.
 *   20  skip         no consent → position 50
 *   30  the study    two pages. Part 1: five personality items and an open
 *                    question; part 2: the plausible-deniability question, age
 *                    and a second open question. Each page ends with an
 *                    agent_probe and a data_quality item; part 1 also stores
 *                    the User-Agent, two client hints and the Signature-Agent
 *                    header.
 *   40  analysis     an end page that reads the participant's own records
 *                    with the formr R package (dq_flags_by_session(), needs
 *                    formr 1.3.0 in OpenCPU) and shows what is flagged. For
 *                    the example only: a real study never shows this.
 *   50  no consent   end page
 *
 * The consent paragraph and the question's wording come from
 * documentation/data_quality/texts.php, as the example survey's and the admin
 * page's do. Re-run after changing them or the units below, and commit the
 * regenerated files.
 *
 *   php bin/gen_data_quality_run.php
 *
 * Exits 0 on success, 1 if a file could not be written.
 */
$texts = require __DIR__ . '/../documentation/data_quality/texts.php';
$outDir = __DIR__ . '/../documentation/run_components';

$item = function ($type, $name, $label = '', array $more = []) {
    return array_merge(['type' => $type, 'name' => $name, 'label' => $label, 'optional' => 0], $more);
};
$optional = ['optional' => 1];

$agree = ['1' => 'Disagree strongly', '2' => 'Disagree a little', '3' => 'Neither agree nor disagree',
    '4' => 'Agree a little', '5' => 'Agree strongly'];
$bfi = [
    'bfi_extra' => '... is talkative.',
    'bfi_agree' => '... tends to find fault with others.',
    'bfi_consc' => '... does a thorough job.',
    'bfi_neuro' => '... gets nervous easily.',
    'bfi_open' => '... has an active imagination.',
];

$consentText = "## Weekends and personality\n\n"
    . "Thank you for your interest in this short example study (about 3 minutes). We ask a few questions about your "
    . "personality and about how you spent your last weekend.\n\n"
    . "### What we record\n\n1. **Your answers.**\n2. **Information to check data quality.** " . $texts['consent'] . "\n\n"
    . "### Storage and your rights\n\n"
    . "*Replace this paragraph with your own:* where the data are stored and who receives them, the legal basis (in the "
    . "EU your consent, Art. 6(1)(a) GDPR; in Germany also § 25(1) TDDDG for reading information from your device), that "
    . "taking part is voluntary and consent can be withdrawn, and the participant's rights.";

$consentItems = [
    $item('note', 'consent_info', $consentText, $optional),
    $item('mc_button', 'consent', 'Do you agree to take part under these conditions?',
        ['class' => 'mc_vertical', 'choice_list' => 'consent', 'choices' => ['1' => 'Yes, I agree', '2' => 'No, I do not agree']]),
    $item('submit', 'consent_submit', 'Continue'),
];

$studyItems = [$item('note', 'p1_intro', "### Part 1 of 2\n\nPlease answer on your own. **I see myself as someone who ...**", $optional)];
foreach ($bfi as $name => $label) {
    $studyItems[] = $item('mc_button', $name, $label,
        ['choice_list' => 'agree5'] + ($name === 'bfi_extra' ? ['choices' => $agree] : []));
}
$studyItems = array_merge($studyItems, [
    $item('textarea', 'weekend_text', 'In two or three sentences: what did you do last weekend?'),
    // What the request says about its sender. Stored by the server; on v2 when the form is first opened.
    $item('browser', 'dq_srv_ua', '', $optional),
    $item('server', 'dq_ch_platform', '', $optional + ['type_options' => 'HTTP_SEC_CH_UA_PLATFORM']),
    $item('server', 'dq_ch_mobile', '', $optional + ['type_options' => 'HTTP_SEC_CH_UA_MOBILE']),
    $item('server', 'dq_sig_agent', '', $optional + ['type_options' => 'HTTP_SIGNATURE_AGENT']),
    // The hidden note and field (default wording), then the page's record, just above the submit button.
    $item('agent_probe', 'dq_agent_p1', '', $optional),
    $item('data_quality', 'dq_p1', '', $optional),
    $item('submit', 'p1_submit', 'Continue'),

    $item('note', 'p2_intro', '### Part 2 of 2', $optional),
    $item('mc_button', 'rrt_ai', $texts['rrt_question'], ['choice_list' => 'yes_no', 'choices' => ['1' => 'Yes', '2' => 'No']]),
    $item('number', 'age', 'How old are you?', ['type_options' => '16,99,1']),
    $item('textarea', 'purpose_text', 'What do you think this study is about?'),
    $item('agent_probe', 'dq_agent_p2', '', $optional),
    $item('data_quality', 'dq_p2', '', $optional),
    $item('submit', 'p2_submit', 'Finish'),
]);

// The analysis page. STUDY is replaced by the study's name: in a run's R, the
// survey's data are a data frame of that name, holding the participant's rows.
$analysis = file_get_contents(__DIR__ . '/../documentation/data_quality/example_analysis_page.md');
if ($analysis === false) {
    fwrite(STDERR, "gen_data_quality_run: documentation/data_quality/example_analysis_page.md is missing\n");
    exit(1);
}

$settings = ['maximum_number_displayed' => 0, 'displayed_percentage_maximum' => 100, 'add_percentage_points' => 0,
    'enable_instant_validation' => 0, 'expire_after' => 0, 'google_file_id' => '', 'unlinked' => 0,
    'expire_invitation_after' => 0, 'expire_invitation_grace' => 0, 'hide_results' => 0, 'use_paging' => 0];
$v2Settings = ['rendering_mode' => 'v2', 'offline_mode' => 1, 'allow_previous' => 0, 'layout' => 'default', 'language' => 'en'];

$variants = [
    'Data_Quality' => ['unit' => 'Survey', 'suffix' => '', 'settings' => $settings],
    'Data_Quality_v2' => ['unit' => 'Form', 'suffix' => '_v2', 'settings' => $settings + $v2Settings],
];
foreach ($variants as $file => $variant) {
    $consent = 'dq_weekend_consent' . $variant['suffix'];
    $study = 'dq_weekend_study' . $variant['suffix'];
    $survey = function ($name, $position, $description, $items) use ($variant) {
        return ['type' => $variant['unit'], 'description' => $description, 'position' => $position, 'special' => null,
            'survey_data' => ['name' => $name, 'items' => $items, 'settings' => $variant['settings']]];
    };
    $bundle = ['name' => $file, 'units' => [
        $survey($consent, 10, 'consent (nothing is recorded here)', $consentItems),
        ['type' => 'SkipForward', 'description' => 'no consent: to the last page', 'position' => 20, 'special' => null,
            'condition' => "is.na(tail({$consent}\$consent, 1)) || tail({$consent}\$consent, 1) != 1",
            'if_true' => 50, 'automatically_jump' => 1, 'automatically_go_on' => 1],
        $survey($study, 30, 'the study: two pages, each with an agent_probe and a data_quality item', $studyItems),
        ['type' => 'Endpage', 'description' => 'analysis of the own records with the formr R package (example only)',
            'position' => 40, 'special' => null, 'body' => str_replace('STUDY', $study, $analysis), 'title' => null],
        ['type' => 'Endpage', 'description' => 'no consent', 'position' => 50, 'special' => null,
            'body' => "## Thank you\n\nYou did not agree to take part, so nothing further is recorded. You can close this window.",
            'title' => null],
    ]];
    $out = "$outDir/$file.json";
    $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($out, $json) === false) {
        fwrite(STDERR, "gen_data_quality_run: could not write $out — run this on the host, not inside formr_app\n");
        exit(1);
    }
    echo "wrote $out (" . strlen($json) . " bytes)\n";
}
