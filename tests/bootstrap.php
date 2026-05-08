<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads the regular setup.php so the autoloader, Config, and Crypto are
 * initialized, then forces the database config to an in-memory SQLite
 * connection so model constructors that boot through DB::getInstance()
 * don't need a live MySQL server. DB::__construct branches on the
 * 'driver' key.
 *
 * Tests that need a real database stay opt-in via @group integration and
 * are skipped by the default --exclude-group integration filter in the
 * PHPUnit GitHub Actions workflow.
 */
require_once __DIR__ . '/../setup.php';

Config::initialize(array(
    'database' => (object) array(
        'driver' => 'sqlite',
        'database' => ':memory:',
    ),
));

// Seed an empty schema for the tables that Model constructors blindly
// query through Model::load (e.g. SurveyStudy::load filters survey_studies
// by whatever keys are in $options). The columns are the union of the
// findRow filters and the assignProperties targets — types are loose
// because no test reads values back from these rows; queries just need
// to parse and return zero rows.
$pdo = DB::getInstance()->pdo();
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS survey_studies (
    id INTEGER PRIMARY KEY,
    user_id INTEGER, name TEXT, results_table TEXT, valid INTEGER,
    original_file TEXT, google_file_id TEXT,
    unlinked INTEGER, hide_results INTEGER, use_paging INTEGER,
    maximum_number_displayed INTEGER, displayed_percentage_maximum INTEGER,
    add_percentage_points INTEGER, expire_after INTEGER,
    expire_invitation_after INTEGER, expire_invitation_grace INTEGER,
    enable_instant_validation INTEGER, created TEXT, modified TEXT
);
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS survey_users (
    id INTEGER PRIMARY KEY,
    email TEXT, password TEXT, admin INTEGER, user_code TEXT,
    referrer_code TEXT, first_name TEXT, last_name TEXT, affiliation TEXT,
    created TEXT, email_verified INTEGER
);
SQL);
