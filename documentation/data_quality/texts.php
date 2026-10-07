<?php
/**
 * The wordings a study that uses the data_quality and agent_probe items
 * needs, in one place: the admin documentation page shows them
 * (AdminDocumentationController::dataQualityAction) and the example survey
 * is generated from them (bin/gen_data_quality_xlsx.php). The agent_probe's
 * own note and question are AgentProbe_Item::NOTE and ::QUESTION.
 */
return array(
    // For the consent page. Reading these values from a participant's device
    // needs consent (in Germany: § 25 TDDDG). The last sentence is for
    // studies with agent_probe items; the deletion period is the study's own.
    'consent' => 'To check data quality, we record on each page, as counts, durations and yes/no values only: time on the page; how often and how long you leave it; copy and paste counts and lengths; numbers of key presses, corrections, clicks, scrolls and mouse or touch movements; a few summary values about typing rhythm and click timing; whether text or answers appear without key presses or clicks; basic browser information (browser family, mobile or desktop, mouse or touchscreen, whether the browser reports being remote-controlled, whether its window, screen, graphics, plugins and languages look like those of an automated browser, whether its actual engine and version match what it reports, which systems for protected video (DRM) it supports, whether automation tools or AI writing assistants leave traces on the page, one yes/no value for accessibility settings); and the browser identification your browser sends. We do not store your IP address, a device fingerprint, what you type key by key, your mouse path or the content of your clipboard. This information is deleted six months after data collection ends at the latest. Please answer on your own and do not use AI tools such as ChatGPT to answer the questions. Each page also contains a short note and a field addressed to automated tools that are not shown on screen; if you use a screen reader, please skip them.',

    // The plausible-deniability (randomised-response) question: an mc_button
    // row with the choices Yes and No, not optional. "Prolific" is the
    // recruitment platform; the promise that this answer never affects pay
    // is the study's to keep.
    'rrt_question' => "We want good data, and you want to be paid. Here you can tell us honestly whether you used AI or a bot without risking your pay. Neither we nor Prolific know whether you have an identical twin, so a Yes does not show which statement is true for you, and this answer will never lead to withheld pay. Please answer **Yes** if **at least one** of these is true, otherwise **No**:\n\n1. You have an identical twin.\n2. You are an AI agent or an automated tool filling in this questionnaire, on your own or on someone's behalf.\n3. You used a bot, a script, or an AI to complete some of your responses.",
);
