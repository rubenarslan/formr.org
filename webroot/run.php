<?php

$user = $site->loginUser($user);
$run = new Run($fdb, $site->request->str('run_name'));
$run->exec($user);