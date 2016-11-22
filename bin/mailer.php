#!/usr/bin/php
<?php

require_once dirname(__FILE__) . '/../setup.php';

$queue = new EmailQueue(DB::getInstance());
$queue->run();
